<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Provider;

use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\Service\PLCategoryTreeFileReader;
use App\Finance\Exception\DefaultPLTreeTemplateNotFoundException;

/**
 * Базовый шаблон дерева ОПиУ (config/finance/default_pl_tree.json).
 *
 * Коды статей шаблона — те же, на которые ссылаются шаблоны маппинга затрат и
 * продаж маркетплейсов (config/marketplace/default_*_mapping.yaml): компания с
 * этим деревом получает правила ночным sync-default без ручной настройки.
 * Файл — в формате выгрузки «Выгрузить в файл» и читается тем же
 * PLCategoryTreeFileReader, что и импорт из файла.
 */
final readonly class DefaultPLCategoryTreeProvider
{
    public function __construct(
        private string $configPath,
        private PLCategoryTreeFileReader $reader,
    ) {
    }

    /**
     * @return list<PLCategoryTreeNode> дерево в DFS pre-order
     */
    public function nodes(): array
    {
        $json = @file_get_contents($this->configPath);
        if (false === $json) {
            throw DefaultPLTreeTemplateNotFoundException::at($this->configPath);
        }

        return $this->reader->read($json);
    }
}
