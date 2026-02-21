<?php

namespace App\Marketplace\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analyze-processing-cost',
    description: 'Анализирует поля для затраты "Обработка товара"'
)]
class AnalyzeProcessingCostCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Анализ полей для "Обработка товара"');

        // Находим последние raw документы
        $conn = $this->em->getConnection();

        $sql = "SELECT id, raw_data, synced_at
                FROM marketplace_raw_documents
                WHERE document_type = 'sales_report'
                AND raw_data IS NOT NULL
                ORDER BY synced_at DESC
                LIMIT 5";

        $result = $conn->executeQuery($sql);
        $documents = $result->fetchAllAssociative();

        if (empty($documents)) {
            $io->error('Не найдено raw документов. Загрузите данные из WB.');
            return Command::FAILURE;
        }

        $io->section('Поиск записей "Обработка товара"');

        $found = false;
        $examples = [];

        foreach ($documents as $doc) {
            $rawData = json_decode($doc['raw_data'], true);

            if (!$rawData) {
                continue;
            }

            foreach ($rawData as $item) {
                if (($item['supplier_oper_name'] ?? '') === 'Обработка товара') {
                    $found = true;
                    $examples[] = $item;

                    if (count($examples) >= 3) {
                        break 2;
                    }
                }
            }
        }

        if (!$found) {
            $io->warning('Записи "Обработка товара" не найдены в последних документах');
            $io->text([
                'Попробуйте:',
                '1. Загрузить новый отчёт от WB',
                '2. Обработать данные',
                '3. Запустить эту команду снова',
            ]);
            return Command::FAILURE;
        }

        $io->success(sprintf('Найдено примеров: %d', count($examples)));

        // Анализируем поля
        $numericFields = [];

        foreach ($examples as $i => $item) {
            $io->section(sprintf('Пример #%d', $i + 1));

            // Основная информация
            $io->table(
                ['Поле', 'Значение'],
                [
                    ['srid', $item['srid'] ?? 'N/A'],
                    ['nm_id', $item['nm_id'] ?? 'N/A'],
                    ['ts_name', $item['ts_name'] ?? 'N/A'],
                    ['sale_dt', $item['sale_dt'] ?? $item['rr_dt'] ?? 'N/A'],
                ]
            );

            // Числовые поля (кандидаты на сумму)
            $candidates = [
                'delivery_rub',
                'ppvz_reward',
                'ppvz_for_pay',
                'acquiring_fee',
                'penalty',
                'storage_cost',
                'retail_amount',
                'ppvz_vw',
                'ppvz_vw_nds',
                'acceptance',
            ];

            $tableData = [];
            foreach ($candidates as $field) {
                $value = $item[$field] ?? null;

                if (!isset($numericFields[$field])) {
                    $numericFields[$field] = ['count' => 0, 'non_zero' => 0, 'values' => []];
                }

                if ($value !== null) {
                    $numericFields[$field]['count']++;
                    $numericFields[$field]['values'][] = $value;

                    if ((float)$value != 0) {
                        $numericFields[$field]['non_zero']++;
                        $tableData[] = [$field, $value, '✅'];
                    } else {
                        $tableData[] = [$field, $value, ''];
                    }
                }
            }

            if (!empty($tableData)) {
                $io->table(['Поле', 'Значение', 'Кандидат'], $tableData);
            }
        }

        // Итоговая статистика
        $io->section('Итоговая статистика по полям');

        $statsData = [];
        foreach ($numericFields as $field => $stats) {
            if ($stats['non_zero'] > 0) {
                $avgValue = count($stats['values']) > 0
                    ? array_sum($stats['values']) / count($stats['values'])
                    : 0;

                $statsData[] = [
                    $field,
                    $stats['count'],
                    $stats['non_zero'],
                    round($avgValue, 2),
                    '✅ ВЕРОЯТНО'
                ];
            }
        }

        if (empty($statsData)) {
            $io->error('Не найдено полей с ненулевыми значениями!');
            return Command::FAILURE;
        }

        usort($statsData, fn($a, $b) => $b[2] <=> $a[2]); // Сортируем по non_zero

        $io->table(
            ['Поле', 'Всего', 'Ненулевых', 'Среднее', 'Рекомендация'],
            $statsData
        );

        // Рекомендации
        $io->section('Рекомендации');

        $topField = $statsData[0][0] ?? null;

        if ($topField) {
            $io->success([
                sprintf('Наиболее вероятное поле для суммы: %s', $topField),
                '',
                'Что делать дальше:',
                '1. Откройте: src/Marketplace/Service/CostCalculator/WbProductProcessingCalculator.php',
                '2. Найдите строку 22: $amount = (float)($item[\'delivery_rub\'] ?? 0);',
                sprintf('3. Замените на: $amount = (float)($item[\'%s\'] ?? 0);', $topField),
                '4. Очистите кеш: php bin/console cache:clear',
            ]);
        }

        return Command::SUCCESS;
    }
}
