<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Domain\Policy\CashAiAutoApplyPolicy;
use App\Cash\Entity\Transaction\CashAiCategorizationSetting;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Transaction\CashAiCategorizationSettingRepository;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

/**
 * Флаг автоприменения ИИ-подсказок статей ДДС для компании и состояние ворот
 * (docs/tasks/ai/stages/stage-2.md, 2.3). Без `--enable`/`--disable` — только показывает.
 * Включённый флаг ещё не применяет статьи: нужны решения людей с точностью из
 * `CashAiAutoApplyPolicy`.
 */
#[AsCommand(
    name: 'app:cash:ai-auto-apply',
    description: 'Shows or changes the AI category auto-apply flag of a company and its quality gate',
)]
final class CashAiAutoApplyCommand extends Command
{
    public function __construct(
        private readonly CashAiCategorizationSettingRepository $settings,
        private readonly CashCategorizationQuery $query,
        private readonly CompanyFacade $companies,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable auto-apply')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable auto-apply');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company-id');
        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');
        try {
            Assert::uuid($companyId, '--company-id must be a UUID');
            Assert::false($enable && $disable, '--enable and --disable are mutually exclusive');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        }

        if ($enable || $disable) {
            if (null === $this->companies->findById($companyId)) {
                $output->writeln('<error>company not found</error>');

                return self::FAILURE;
            }
            $now = $this->clock->now();
            $setting = $this->settings->findForCompany($companyId);
            if (null === $setting) {
                $setting = new CashAiCategorizationSetting($companyId, $now);
                $this->entityManager->persist($setting);
            }
            $setting->changeAutoApply($enable, $now);
            $this->entityManager->flush();
        }

        $enabled = $this->settings->isAutoApplyEnabled($companyId);
        $gate = $this->query->autoApplyGateStats($companyId, CashAiAutoApplyPolicy::MIN_CONFIDENCE);
        $precision = $gate->precision();
        $closed = CashAiAutoApplyPolicy::gateClosedReason($enabled, $gate);
        $output->writeln(sprintf('flag        %s', $enabled ? 'on' : 'off'));
        $output->writeln(sprintf(
            'decisions   %d of %d needed (confidence >= %.2f)',
            $gate->decisions(),
            CashAiAutoApplyPolicy::MIN_DECISIONS,
            CashAiAutoApplyPolicy::MIN_CONFIDENCE,
        ));
        $output->writeln(sprintf(
            'precision   %s, needed %.0f%%',
            null === $precision ? 'n/a' : sprintf('%.1f%%', 100 * $precision),
            100 * CashAiAutoApplyPolicy::MIN_PRECISION,
        ));
        $output->writeln(sprintf('auto-applied %d, corrected by people %d', $gate->autoApplied, $gate->autoCorrected));
        $output->writeln('gate        '.(null === $closed ? 'open' : 'closed: '.$closed));

        return self::SUCCESS;
    }
}
