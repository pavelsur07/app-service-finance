<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Provider;

use App\Company\Enum\BusinessProfile;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\Service\PLCategoryTreeFileReader;
use App\Finance\Exception\DefaultPLTreeTemplateNotFoundException;

/**
 * Стартовые шаблоны дерева ОПиУ по профилю бизнеса. Реестр «профиль → файл» — параметр
 * `finance.pl_templates` в config/services.yaml; файлы — config/finance/pl_templates/.
 *
 * Коды статей одинаковы во всех шаблонах (TemplateRegistryTest) и совпадают с
 * кодами шаблонов маппинга затрат и продаж маркетплейсов
 * (config/marketplace/default_*_mapping.yaml): компания с маркетплейсным деревом получает
 * правила ночным sync-default без ручной настройки. Файл — в формате выгрузки
 * «Выгрузить в файл» и читается тем же PLCategoryTreeFileReader, что и импорт из файла;
 * `profile` и `templateVersion` конверта читатель импорта игнорирует.
 */
final readonly class DefaultPLCategoryTreeProvider
{
    /**
     * @param array<string, string> $templatePaths значение BusinessProfile → путь к файлу
     */
    public function __construct(
        private array $templatePaths,
        private PLCategoryTreeFileReader $reader,
        private ?string $marketplaceExtensionPath = null,
    ) {
    }

    /**
     * @return list<PLCategoryTreeNode>
     */
    public function nodes(BusinessProfile $profile): array
    {
        return $this->reader->read($this->json($profile));
    }

    /**
     * Версия содержимого шаблона (`templateVersion`) — для лога применения.
     */
    public function templateVersion(BusinessProfile $profile): int
    {
        $envelope = json_decode($this->json($profile), true);

        return \is_array($envelope) && \is_int($envelope['templateVersion'] ?? null) ? $envelope['templateVersion'] : 0;
    }

    /**
     * «Базовый» шаблон со статьями маркетплейса — полное дерево: шаблон UNIVERSAL, в который
     * вставлены статьи маркетплейсного (те же коды и места), и формулы REV_TOTAL/EBITDA с ними.
     *
     * @return list<PLCategoryTreeNode>
     */
    public function marketplaceExtension(): array
    {
        return $this->reader->read($this->extensionJson());
    }

    public function marketplaceExtensionVersion(): int
    {
        $envelope = json_decode($this->extensionJson(), true);

        return \is_array($envelope) && \is_int($envelope['templateVersion'] ?? null) ? $envelope['templateVersion'] : 0;
    }

    private function extensionJson(): string
    {
        $path = $this->marketplaceExtensionPath ?? throw DefaultPLTreeTemplateNotFoundException::at('finance.pl_marketplace_extension');
        $json = @file_get_contents($path);
        if (false === $json) {
            throw DefaultPLTreeTemplateNotFoundException::at($path);
        }

        return $json;
    }

    private function json(BusinessProfile $profile): string
    {
        $path = $this->templatePaths[$profile->value] ?? throw DefaultPLTreeTemplateNotFoundException::forProfile($profile->value);
        $json = @file_get_contents($path);
        if (false === $json) {
            throw DefaultPLTreeTemplateNotFoundException::at($path);
        }

        return $json;
    }
}
