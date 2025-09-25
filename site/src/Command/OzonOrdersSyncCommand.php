<?php

namespace App\Command;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Repository\Ozon\OzonSyncCursorRepository;
use App\Service\Ozon\OzonOrderSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ozon:orders:sync')]
class OzonOrdersSyncCommand extends Command
{
    public function __construct(
        private CompanyRepository $companyRepo,
        private OzonOrderSyncService $syncService,
        private OzonSyncCursorRepository $cursorRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED)
            ->addOption('scheme', null, InputOption::VALUE_REQUIRED)
            ->addOption('since', null, InputOption::VALUE_OPTIONAL)
            ->addOption('to', null, InputOption::VALUE_OPTIONAL)
            ->addOption('status', null, InputOption::VALUE_OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company');
        $scheme = strtoupper((string) $input->getOption('scheme'));
        /** @var Company|null $company */
        $company = $this->companyRepo->find($companyId);
        if (!$company) {
            $output->writeln('<error>Company not found</error>');

            return Command::FAILURE;
        }

        $sinceOpt = $input->getOption('since');
        $toOpt = $input->getOption('to');
        $since = $sinceOpt ? new \DateTimeImmutable($sinceOpt, new \DateTimeZone('UTC')) : null;
        $to = $toOpt ? new \DateTimeImmutable($toOpt, new \DateTimeZone('UTC')) : null;

        if (!$since || !$to) {
            $cursor = $this->cursorRepo->findOneByCompanyAndScheme($company, $scheme);
            if ($cursor) {
                $since = $since ?? $cursor->getLastSince();
                $to = $to ?? $cursor->getLastTo();
            }
        }

        if (!$since || !$to) {
            $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $since = $to->sub(new \DateInterval('P3D'));
        }

        $scheme = $input->getOption('scheme');
        $scheme = $scheme ? strtoupper((string) $scheme) : null;

        if ('FBS' === $scheme) {
            // Явно запросили только FBS
            $statusParam = (string) $input->getOption('status');
            $status = $statusParam
                ? (str_contains($statusParam, ',') ? array_map('trim', explode(',', $statusParam)) : $statusParam)
                : null;

            $result = $this->syncService->syncFbs($company, $since, $to, $status);
        } elseif ('FBO' === $scheme) {
            // Явно запросили только FBO
            $result = $this->syncService->syncFbo($company, $since, $to);
        } else {
            // Схема не указана — как в UI: запускаем ОБЕ
            $statusParam = (string) $input->getOption('status');
            $status = $statusParam
                ? (str_contains($statusParam, ',') ? array_map('trim', explode(',', $statusParam)) : $statusParam)
                : null;

            $r1 = $this->syncService->syncFbs($company, $since, $to, $status);
            $r2 = $this->syncService->syncFbo($company, $since, $to);

            // Суммируем для итогового вывода
            $result = [
                'orders' => (int) ($r1['orders'] ?? 0) + (int) ($r2['orders'] ?? 0),
                'statusChanges' => (int) ($r1['statusChanges'] ?? 0) + (int) ($r2['statusChanges'] ?? 0),
            ];
        }

        $output->writeln(sprintf('Postings processed: %d, new statuses: %d', $result['orders'], $result['statusChanges']));

        return Command::SUCCESS;
    }
}
