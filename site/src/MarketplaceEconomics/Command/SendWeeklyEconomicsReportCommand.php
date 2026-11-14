<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Command;

use App\Company\Facade\CompanyFacade;
use App\MarketplaceEconomics\Application\DTO\SkuEconomicsFilter;
use App\MarketplaceEconomics\Application\DTO\WeeklyEconomicsSummary;
use App\MarketplaceEconomics\Application\Service\SkuEconomicsReportBuilder;
use App\MarketplaceEconomics\Infrastructure\Export\SkuEconomicsXlsxExporter;
use App\MarketplaceEconomics\Infrastructure\Query\EconomicsStore;
use App\Notification\DTO\EmailMessage;
use App\Notification\Service\NotificationRouter;
use App\Telegram\Facade\TelegramFacadeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Отчёт R3 «Неделя» (`REPORTS.md` §4, cron по понедельникам): за прошлую неделю — сводка и xlsx
 * отчёта R1 владельцу компании на почту и привязанным пользователям в Telegram.
 *
 * Включение — `ECONOMICS_WEEKLY_REPORT_COMPANIES` (UUID через запятую, * — все, пусто — выключено).
 * Журнал `me_weekly_report` держит «одна отправка на неделю и канал»: отметка до отправки,
 * снимается при сбое — повторный прогон дошлёт только неотправленное. Транзакций нет: только чтение
 * витрины и автокоммит отметок.
 */
#[AsCommand(name: 'app:marketplace-economics:weekly-report', description: 'Weekly SKU economics report: e-mail and Telegram')]
final class SendWeeklyEconomicsReportCommand extends Command
{
    use LockableTrait;

    public const EMAIL = 'email';
    public const TELEGRAM = 'telegram';
    private const ALL = '*';

    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(
        private readonly EconomicsStore $store,
        private readonly SkuEconomicsReportBuilder $reports,
        private readonly SkuEconomicsXlsxExporter $exporter,
        private readonly CompanyFacade $companies,
        private readonly NotificationRouter $notifier,
        private readonly TelegramFacadeInterface $telegram,
        private readonly Environment $twig,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::ECONOMICS_WEEKLY_REPORT_COMPANIES)%')]
        ?string $allowed = null,
    ) {
        parent::__construct();
        $this->allowed = array_values(array_filter(array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', (string) $allowed))));
    }

    protected function configure(): void
    {
        $this
            ->addOption('week', null, InputOption::VALUE_REQUIRED, 'Любая дата недели (Y-m-d); по умолчанию — прошлая неделя')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Только эта компания (из включённых)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать сводку, ничего не отправлять');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ([] === $this->allowed) {
            $output->writeln('weekly-report: выключено (ECONOMICS_WEEKLY_REPORT_COMPANIES пуст)');

            return self::SUCCESS;
        }
        if (!$this->lock()) {
            $output->writeln('weekly-report: уже выполняется');

            return self::SUCCESS;
        }

        $week = $input->getOption('week');
        $day = \is_string($week) ? new \DateTimeImmutable($week) : $this->clock->now()->modify('-7 days');
        $from = $day->setTime(0, 0)->modify('monday this week');
        $to = $from->modify('+6 days');
        $only = $input->getOption('company');
        $dryRun = (bool) $input->getOption('dry-run');

        $count = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($this->store->companiesWithDays($from, $to) as $companyId) {
            if ((\is_string($only) && $only !== $companyId) || (!\in_array(self::ALL, $this->allowed, true) && !\in_array($companyId, $this->allowed, true))) {
                continue;
            }
            $context = ['companyId' => $companyId, 'week' => $from->format('Y-m-d')];
            $this->logger->info('Weekly economics report: start.', $context);
            try {
                $sent = $this->sendCompany($companyId, $from, $to, $dryRun, $output);
                ++$count[$sent ? 'sent' : 'skipped'];
                $this->logger->info('Weekly economics report: finish.', $context + ['sent' => $sent]);
            } catch (\Throwable $e) {
                ++$count['failed'];
                $this->logger->warning('Weekly economics report: company failed, next run retries.', $context + ['exception' => $e]);
            }
        }
        if ($count['failed'] > 0) {
            $this->logger->error('Weekly economics report: some companies failed.', $count + ['week' => $from->format('Y-m-d')]);
        }
        $output->writeln(sprintf('weekly-report %s: %d отправлено, %d пропущено, %d сбоев', $from->format('Y-m-d'), $count['sent'], $count['skipped'], $count['failed']));
        $this->release();

        return 0 === $count['failed'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return bool true — хотя бы один канал отправлен в этом прогоне */
    private function sendCompany(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $dryRun, OutputInterface $output): bool
    {
        $company = $this->companies->findById($companyId);
        if (null === $company || $company->isDeletionRequested()) {
            return false;
        }
        $report = $this->reports->build($companyId, new SkuEconomicsFilter($from, $to));
        if (!$report->hasData()) {
            return false;
        }
        $summary = WeeklyEconomicsSummary::fromReport($report);
        $text = trim($this->twig->render('notifications/email/economics_weekly_report.txt.twig', ['summary' => $summary]));
        if ($dryRun) {
            $output->writeln(sprintf("== %s (%s)\n%s\n", $company->getName(), $companyId, $text));

            return false;
        }

        $subject = sprintf('%s: неделя %s–%s', $company->getName(), $from->format('d.m'), $to->format('d.m.Y'));
        $fileName = sprintf('economics-%s.xlsx', $from->format('Y-m-d'));
        $path = $this->exporter->writeFile($report, true);
        $sent = false;
        try {
            $email = $company->getUser()?->getEmail();
            if (null !== $email && '' !== $email && $this->store->claimWeeklyReport($companyId, $from, self::EMAIL)) {
                try {
                    $sent = $this->notifier->send(self::EMAIL, new EmailMessage(
                        to: $email,
                        subject: $subject,
                        htmlTemplate: 'notifications/email/economics_weekly_report.html.twig',
                        textTemplate: 'notifications/email/economics_weekly_report.txt.twig',
                        vars: ['summary' => $summary],
                        attachments: [['path' => $path, 'name' => $fileName, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']],
                    ));
                } catch (\Throwable $e) {
                    $this->store->releaseWeeklyReport($companyId, $from, self::EMAIL);

                    throw $e;
                }
                if (!$sent) {
                    $this->store->releaseWeeklyReport($companyId, $from, self::EMAIL);
                }
            }

            if ($this->store->claimWeeklyReport($companyId, $from, self::TELEGRAM)) {
                $result = $this->telegram->sendDocumentToCompany($companyId, $path, $fileName, $subject."\n\n".$text);
                if (0 === $result['sent']) {
                    // нет привязок или все отправки упали — неделя остаётся неотправленной
                    $this->store->releaseWeeklyReport($companyId, $from, self::TELEGRAM);
                } else {
                    $sent = true;
                }
                if ($result['failed'] > 0) {
                    $this->logger->warning('Weekly economics report: Telegram partially failed.', ['companyId' => $companyId] + $result);
                }
            }
        } finally {
            @unlink($path);
        }

        return $sent;
    }
}
