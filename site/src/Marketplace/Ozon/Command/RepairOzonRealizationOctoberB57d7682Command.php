<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Command;

use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Application\DTO\OzonRealizationIntegrity;
use App\Marketplace\Application\ReopenMonthStageAction;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MonthCloseAdvisoryLockQuery;
use App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction;
use App\Marketplace\Ozon\Application\Realization\OzonRealizationReport;
use App\Marketplace\Ozon\Infrastructure\Query\OzonRealizationIntegrityQuery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:marketplace:ozon-realization:repair-october-b57d7682', description: 'Одноразовый ремонт двойной реализации октября; без --execute только чтение')]
final class RepairOzonRealizationOctoberB57d7682Command extends Command
{
    private const COMPANY = 'b57d7682-505f-4b74-86f8-953d32d59874';
    private const RAW = 'dda3485d-1aa8-48b5-9e12-bc8f06ddb239';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OzonRealizationIntegrityQuery $integrity,
        private readonly MonthCloseAdvisoryLockQuery $lock,
        private readonly ReopenMonthStageAction $reopen,
        private readonly ProcessOzonRealizationAction $process,
        private readonly CloseMonthStageAction $close,
        private readonly FinanceFacade $finance,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Изменить только целевой октябрь после отдельного одобрения')
            ->addOption('expected-raw-hash', null, InputOption::VALUE_REQUIRED, 'Хеш raw из предварительной проверки; обязателен для --execute');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $committed = false;
        try {
            $report = $this->integrity->fetch(self::COMPANY, self::RAW);
            $stage = $this->stage();
            $io->writeln(sprintf('raw hash: %s; raw rows: %d; normalized rows: %d; sales: %s; returns: %s',
                $report->rawHash, $report->sourceRows, $report->actualRows, $report->sales, $report->returns));
            $rows = $this->documentAmounts($stage['document']);
            $io->writeln(sprintf('current document: %s; stage: closed (final); finance lock: %s', $stage['document'], $stage['financeLockBefore'] ?? 'none'));
            foreach ($rows as $key => $amount) {
                $code = explode('|', $key)[0];
                if (in_array($code, ['REV_SPP_SALES', 'REV_SPP_RETURNS'], true)) {
                    $io->writeln(sprintf('current document %s: %s', $code, $amount));
                }
            }
            if (0 === $report->sourceRows || $report->sourceRows !== $report->expectedRows) {
                throw new \DomainException('Пустой или частично распознанный источник: требуется отдельная диагностика.');
            }
            if ($report->matches && $this->documentMatches($rows, $report, 1)) {
                $this->assertDocumentLinks($stage['document']);
                $this->assertRegisterConsistent();
                $io->success('already repaired; no changes');

                return self::SUCCESS;
            }
            $this->assertRepairable($report, $rows);
            $io->writeln('doubled: every source-row multiplicity is exactly 2x; only sales_returns will be rebuilt');
            if (!$input->getOption('execute')) {
                $io->note('Read-only preview. No changes. Execute requires this raw hash and separate production approval.');

                return self::SUCCESS;
            }
            $hash = $input->getOption('expected-raw-hash');
            if (!is_string($hash) || 1 !== preg_match('/^[a-f0-9]{32}$/D', $hash) || !hash_equals($report->rawHash, $hash)) {
                throw new \DomainException('Требуется --expected-raw-hash из актуального dry-run.');
            }
            $this->em->wrapInTransaction(function () use ($hash): void {
                $this->lock->lock(self::COMPANY, MarketplaceType::OZON, 2026, 10);
                $db = $this->em->getConnection();
                $db->fetchOne('SELECT id FROM marketplace_raw_documents WHERE company_id = ? AND id = ? FOR UPDATE', [self::COMPANY, self::RAW]);
                $this->em->clear();
                $report = $this->integrity->fetch(self::COMPANY, self::RAW);
                if (!hash_equals($report->rawHash, $hash)) {
                    throw new \DomainException('Raw изменился во время ожидания. Ремонт отменён.');
                }
                $stage = $this->stage();
                $before = $this->documentAmounts($stage['document']);
                $this->assertRepairable($report, $before);
                ($this->reopen)(new ReopenMonthStageCommand(self::COMPANY, 'ozon', 2026, 10, CloseStage::SALES_RETURNS));
                ($this->process)(self::COMPANY, self::RAW);
                if (!$this->integrity->fetch(self::COMPANY, self::RAW)->matches) {
                    throw new \DomainException('Обработанные строки не совпали с raw.');
                }
                ($this->close)(new CloseMonthStageCommand(self::COMPANY, 'ozon', 2026, 10, CloseStage::SALES_RETURNS->value, $stage['actor']));
                $afterStage = $this->stage();
                $after = $this->documentAmounts($afterStage['document']);
                if ($stage['dimensions'] !== $afterStage['dimensions'] || !$this->documentMatches($after, $report, 1)
                    || array_keys($before) !== array_keys($after)) {
                    throw new \DomainException('Документ или его аналитики не совпали с ожидаемым результатом.');
                }
                foreach ($before as $key => $amount) {
                    $spp = in_array(explode('|', $key)[0], ['REV_SPP_SALES', 'REV_SPP_RETURNS'], true);
                    if (0 !== bccomp($amount, $spp ? bcmul($after[$key], '2', 2) : $after[$key], 2)) {
                        throw new \DomainException('Изменились суммы вне согласованного ремонта.');
                    }
                }
                $this->assertRegisterConsistent();
                $this->assertDocumentLinks($afterStage['document']);
            });
            $committed = true;
            $this->finance->invalidatePLRegisterCache(self::COMPANY);
            $io->success('repaired; realization, document and register verified');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($committed ? 'Ремонт зафиксирован, но сброс кэша не завершён. Повторный ремонт данных не требуется.'
                : ($e instanceof \DomainException ? $e->getMessage() : 'Ремонт не завершён: '.get_debug_type($e)));
            if (!$e instanceof \DomainException) {
                $this->logger->error('Ozon realization repair failed', ['company_id' => self::COMPANY, 'exception_class' => $e::class, 'committed' => $committed]);
            }

            return self::FAILURE;
        }
    }

    /** @return array{document:string, actor:string, dimensions:array<string,mixed>, financeLockBefore:?string} */
    private function stage(): array
    {
        $db = $this->em->getConnection();
        $raw = $db->fetchAssociative("SELECT raw_data->'result'->'header' header FROM marketplace_raw_documents WHERE company_id = ? AND id = ? AND period_from = '2026-10-01' AND period_to = '2026-10-31'", [self::COMPANY, self::RAW]);
        $header = false !== $raw && is_string($raw['header']) ? json_decode($raw['header'], true, 512, \JSON_THROW_ON_ERROR) : null;
        if (false === $raw || !OzonRealizationReport::hasMatchingHeader(['result' => ['header' => $header]], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))) {
            throw new \DomainException('Raw не соответствует целевому октябрю.');
        }
        $row = $db->fetchAssociative("SELECT m.stage_sales_returns_status status, m.stage_sales_returns_pl_document_ids documents, m.stage_sales_returns_closed_by_user_id actor, m.settings, c.finance_lock_before FROM marketplace_month_closes m JOIN companies c ON c.id=m.company_id WHERE m.company_id = ? AND m.marketplace = 'ozon' AND m.year=2026 AND m.month=10", [self::COMPANY]);
        if (false === $row || 'closed' !== $row['status'] || !is_string($row['actor'])) {
            throw new \DomainException('Ожидался финально закрытый октябрь с известным actor ID.');
        }
        $settings = is_string($row['settings']) ? json_decode($row['settings'], true, 512, \JSON_THROW_ON_ERROR) : [];
        if ($settings['last_close_was_preliminary']['sales_returns'] ?? false) {
            throw new \DomainException('Этап закрыт предварительно, это другой сценарий ремонта.');
        }
        if (is_string($row['finance_lock_before']) && $row['finance_lock_before'] >= '2026-10-31') {
            throw new \DomainException('Октябрь заблокирован для редактирования.');
        }
        $ids = is_string($row['documents']) ? json_decode($row['documents'], true, 512, \JSON_THROW_ON_ERROR) : [];
        if (!is_array($ids) || 1 !== count($ids) || !is_string($ids[0] ?? null)) {
            throw new \DomainException('Ожидался ровно один документ этапа.');
        }
        $dimensions = $db->fetchAssociative("SELECT date::text,project_direction_id,responsibility_center_id,counterparty_id FROM documents WHERE company_id = ? AND id = ? AND date::date = '2026-10-31' AND type = 'marketplace_pl' AND source = 'marketplace_ozon' AND stream = 'revenue' AND status = 'ACTIVE' AND deleted_at IS NULL", [self::COMPANY, $ids[0]]);
        if (false === $dimensions) {
            throw new \DomainException('Не найден ожидаемый активный документ Ozon за октябрь.');
        }

        return ['document' => $ids[0], 'actor' => $row['actor'], 'dimensions' => $dimensions, 'financeLockBefore' => is_string($row['finance_lock_before']) ? $row['finance_lock_before'] : null];
    }

    /** @param array<string,string> $rows */
    private function assertRepairable(OzonRealizationIntegrity $report, array $rows): void
    {
        foreach (array_keys($rows) as $key) {
            if (str_starts_with($key, 'UNCATEGORIZED|')) {
                throw new \DomainException('Документ содержит операцию без статьи: требуется отдельная диагностика.');
            }
        }
        if (0 === $report->sourceRows || $report->sourceRows !== $report->expectedRows || !$report->doubled || !$this->documentMatches($rows, $report, 2)) {
            throw new \DomainException('Не подтверждено точное удвоение исходных строк и документа. Автоматический ремонт запрещён.');
        }
    }

    /** @param array<string,string> $rows */
    private function documentMatches(array $rows, OzonRealizationIntegrity $report, int $factor): bool
    {
        $totals = ['REV_SPP_SALES' => '0.00', 'REV_SPP_RETURNS' => '0.00'];
        foreach ($rows as $key => $amount) {
            [$code, , $flow, $type] = explode('|', $key);
            if (isset($totals[$code])) {
                if ('LEAF_INPUT' !== $type || ('REV_SPP_SALES' === $code ? 'INCOME' : 'EXPENSE') !== $flow) {
                    return false;
                }
                $totals[$code] = bcadd($totals[$code], $this->abs($amount), 2);
            }
        }

        return 0 === bccomp($totals['REV_SPP_SALES'], bcmul($report->sales, (string) $factor, 2), 2)
            && 0 === bccomp($totals['REV_SPP_RETURNS'], bcmul($report->returns, (string) $factor, 2), 2);
    }

    /** @return array<string,string> */
    private function documentAmounts(string $documentId): array
    {
        return $this->em->getConnection()->fetchAllKeyValue("SELECT concat_ws('|',COALESCE(c.code,'UNCATEGORIZED'),COALESCE(c.id::text,''),COALESCE(c.flow,'NONE'),COALESCE(c.type,'NONE'),COALESCE(COALESCE(o.project_direction_id,d.project_direction_id)::text,''),COALESCE(COALESCE(o.responsibility_center_id,d.responsibility_center_id)::text,''),COALESCE(COALESCE(o.counterparty_id,d.counterparty_id)::text,'')) key, SUM(o.amount)::text amount FROM documents d JOIN document_operations o ON o.document_id=d.id LEFT JOIN pl_categories c ON c.id=o.category_id AND c.company_id=d.company_id WHERE d.company_id = ? AND d.id = ? GROUP BY key ORDER BY key", [self::COMPANY, $documentId]);
    }

    private function assertRegisterConsistent(): void
    {
        $day = new \DateTimeImmutable('2026-10-31');
        if (!$this->finance->isPLRegisterConsistent(self::COMPANY, $day, $day)) {
            throw new \DomainException('Регистр ОПиУ не совпал с документами по суммам или аналитикам.');
        }
    }

    private function assertDocumentLinks(string $documentId): void
    {
        $unlinked = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_realizations WHERE company_id = ? AND raw_document_id = ? AND pl_document_id IS DISTINCT FROM ?::uuid', [self::COMPANY, self::RAW, $documentId]);
        if (0 !== $unlinked) {
            throw new \DomainException('Обнаружены неверные привязки реализации к документу ОПиУ.');
        }
    }

    private function abs(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }
}
