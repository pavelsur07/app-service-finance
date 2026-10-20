<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Company\Facade\CompanyFacade;
use App\Finance\Exception\PLCategoryCodeAssignmentException;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Writer\DefaultSaleMappingWriter;
use App\Marketplace\Infrastructure\Writer\SaleMappingRetargetWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Одноразовый ремонт ОПиУ компании 19621cff (своё дерево ОПиУ, сверка 20.10.2026):
 *
 * 1. коды из базового шаблона трём строкам ввода без кода — отчёт ОПиУ берёт суммы по коду, у
 *    строки без кода он показывает ноль;
 * 2. правила продаж WB с итоговых строк «Выкупы без СПП» / «Выручка с СПП» — на строки ввода, как
 *    у Ozon этой компании: на итоговую строку сумма не проводится;
 * 3. недостающие правила возвратов с СПП и себестоимости возвратов Ozon и WB. СПП Ozon у компании
 *    берётся из продаж (sale_revenue), поэтому и возврат — из возвратов (return_refund), а не из
 *    отчёта реализации.
 *
 * Всё — одной транзакцией по точным ID с прода; правило, которое смотрит не туда, куда ожидалось,
 * отменяет запуск целиком. Повторный запуск ничего не меняет. Без --execute транзакция
 * откатывается: вывод — ровно то, что будет записано.
 *
 * Удалить после применения на проде.
 */
#[AsCommand(
    name: 'app:marketplace:repair-pl-mapping-19621cff',
    description: 'Одноразово: коды строк ОПиУ и правила продаж/возвратов компании 19621cff (без --execute — предпросмотр с откатом)',
)]
final class RepairPlMapping19621cffCommand extends Command
{
    public const string COMPANY_ID = '19621cff-b028-45d9-9193-11f47ad9a8b2';

    /** Строка ввода → код базового шаблона. */
    public const array CODES = [
        'f580a3c1-342b-449d-9d2c-007be9f9858f' => ['Продажи с СПП', 'REV_SPP_SALES'],
        '63411175-935c-4956-ba2b-38c649314e24' => ['Возвраты с СПП (-)', 'REV_SPP_RETURNS'],
        '762b6a79-4879-4d6b-a24a-4ea778eded3d' => ['Логистика возвратов', 'COGS_RETURNS_DELIVERY'],
    ];

    /** Правило → [подпись, строка сейчас (итоговая), строка ввода]. */
    public const array RETARGETS = [
        '95c8e1a4-d252-462c-9604-af5b27f2c8f9' => ['WB sale_gross: «Выкупы без СПП» → «Продажи без СПП»', '883e8f5a-316a-480b-875c-402110d1a890', '83ad7980-8ed1-4f2e-b994-8527437c8292'],
        '077b53db-0ac7-4034-b6c4-d2d927e3d5a9' => ['WB sale_revenue: «Выручка с СПП» → «Продажи с СПП»', 'c9bf9a27-12b2-44a4-88e4-3c98876c6b85', 'f580a3c1-342b-449d-9d2c-007be9f9858f'],
    ];

    private const string RETURNS_SPP_ID = '63411175-935c-4956-ba2b-38c649314e24';
    private const string COGS_PRODUCT_ID = '55d59732-4f8f-4c0b-a49f-0f641f97e32c';

    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly FinanceFacade $financeFacade,
        private readonly SaleMappingRetargetWriter $retargetWriter,
        private readonly DefaultSaleMappingWriter $mappingWriter,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Записать; без флага — предпросмотр с откатом транзакции');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');

        if (null === $this->companyFacade->findById(self::COMPANY_ID)) {
            $io->error(sprintf('Компания %s не найдена.', self::COMPANY_ID));

            return Command::FAILURE;
        }

        $context = ['company_id' => self::COMPANY_ID, 'execute' => $execute];
        $this->logger->info('[RepairPlMapping19621cff] started', $context);

        $rows = [];
        $failed = 0;
        $changed = 0;
        $committed = false;
        $this->connection->beginTransaction();
        try {
            foreach (self::CODES as $categoryId => [$name, $code]) {
                try {
                    $assigned = $this->financeFacade->assignPlCategoryCode(self::COMPANY_ID, $categoryId, $code);
                    $rows[] = ['код строки', sprintf('%s → %s', $name, $code), $assigned ? 'присвоен' : 'уже был'];
                    $changed += $assigned ? 1 : 0;
                } catch (PLCategoryCodeAssignmentException $e) {
                    ++$failed;
                    $rows[] = ['код строки', sprintf('%s → %s', $name, $code), 'ОШИБКА: '.$e->getMessage()];
                }
            }
            // Коды нужны вставке правил ниже: она сверяет код строки.
            $this->em->flush();

            foreach (self::RETARGETS as $mappingId => [$label, $fromId, $toId]) {
                if (1 === $this->retargetWriter->retarget(self::COMPANY_ID, $mappingId, $fromId, $toId)) {
                    ++$changed;
                    $rows[] = ['перенос правила', $label, 'перенесено'];
                } elseif ($toId === $this->retargetWriter->currentTarget(self::COMPANY_ID, $mappingId)) {
                    $rows[] = ['перенос правила', $label, 'уже перенесено'];
                } else {
                    ++$failed;
                    $rows[] = ['перенос правила', $label, 'ОШИБКА: правило отключено или смотрит на другую строку'];
                }
            }

            foreach ($this->newRules() as [$marketplace, $source, $plCategoryId, $plCode, $label]) {
                $created = $this->mappingWriter->createMapping(self::COMPANY_ID, $marketplace, $source, $plCategoryId, $plCode, true, null);
                $changed += $created;
                $rows[] = ['новое правило', $label, $created > 0 ? 'создано' : 'пропущено: активное правило уже есть'];
            }

            if (0 === $failed && $execute) {
                $this->connection->commit();
                $committed = true;
            }
        } finally {
            if (!$committed) {
                $this->connection->rollBack();
                // После отката в памяти остались присвоенные коды — не отдаём их дальше.
                $this->em->clear();
            }
        }

        $io->table(['Шаг', 'Что', 'Результат'], $rows);
        $summary = ['changed' => $changed, 'failed' => $failed];
        $this->logger->info('[RepairPlMapping19621cff] finished', $context + $summary + ['committed' => $committed]);

        if ($failed > 0) {
            $io->error(sprintf('Ошибок: %d. Транзакция откатана, ничего не записано.', $failed));

            return Command::FAILURE;
        }

        if (!$execute) {
            $io->note(sprintf('Предпросмотр: изменений %d, транзакция откатана. Для записи повторить с --execute.', $changed));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Записано изменений: %d.', $changed));

        return Command::SUCCESS;
    }

    /**
     * Возвраты вычитаются: is_negative. «Возвраты с СПП (-)» без flow — знак берётся из суммы;
     * себестоимость возвратов гасит себестоимость продаж внутри той же строки. Регистр берёт модуль
     * за день, поэтому день, где возвратов по себестоимости больше продаж, дал бы лишний расход:
     * у компании с 01.06.2026 таких дней нет (Ozon 133, WB 89 дней с возвратами), отдельной
     * строки «Себестоимость возвратов» в её дереве нет.
     *
     * @return list<array{0: MarketplaceType, 1: AmountSource, 2: string, 3: string, 4: string}>
     */
    private function newRules(): array
    {
        $rules = [];
        foreach ([MarketplaceType::OZON, MarketplaceType::WILDBERRIES] as $marketplace) {
            $rules[] = [$marketplace, AmountSource::RETURN_REFUND, self::RETURNS_SPP_ID, 'REV_SPP_RETURNS', $marketplace->value.' return_refund → «Возвраты с СПП (-)», минус'];
            $rules[] = [$marketplace, AmountSource::RETURN_COST_PRICE, self::COGS_PRODUCT_ID, 'COGS_PRODUCT', $marketplace->value.' return_cost_price → «Себестоимость товара», минус'];
        }

        return $rules;
    }
}
