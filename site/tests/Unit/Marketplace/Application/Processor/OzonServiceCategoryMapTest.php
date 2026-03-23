<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Минимальный стаб логгера для проверки наличия warning-вызовов.
 * Заменяет Psr\Log\Test\TestLogger который недоступен без psr/log dev-зависимости.
 */
final class WarningCapturingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string}> */
    private array $warnings = [];

    public function log(mixed $level, mixed $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING) {
            $this->warnings[] = ['level' => $level, 'message' => (string) $message];
        }
    }

    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }

    public function reset(): void
    {
        $this->warnings = [];
    }
}

/**
 * Проверяет синхронность OzonServiceCategoryMap:
 * - каждый маппинг ведёт в существующую категорию с правильным именем
 * - getCategoryName не возвращает fallback 'Прочие услуги Ozon' для известных кодов
 * - resolve не логирует warning для известных service names
 *
 * Запуск: php bin/phpunit tests/Marketplace/Application/Processor/OzonServiceCategoryMapTest.php
 */
final class OzonServiceCategoryMapTest extends TestCase
{
    /**
     * Каждый service name из MAP должен резолвиться без warning
     * и возвращать category code с правильным именем (не fallback).
     */
    public function testAllKnownServiceNamesResolveWithoutWarning(): void
    {
        $logger = new WarningCapturingLogger();

        $knownServiceNames = $this->getKnownServiceNames();

        foreach ($knownServiceNames as $serviceName) {
            // Нулевые маркеры (null в MAP) — легитимны, пропускаем
            if (OzonServiceCategoryMap::isZeroMarker($serviceName)) {
                continue;
            }

            $logger->reset();
            $code = OzonServiceCategoryMap::resolve($serviceName, $logger);

            $this->assertFalse(
                $logger->hasWarnings(),
                sprintf('Service name "%s" triggered a warning — добавь в OzonServiceCategoryMap::MAP', $serviceName),
            );

            $this->assertNotNull(
                $code,
                sprintf('Service name "%s" вернул null — только нулевые маркеры должны возвращать null', $serviceName),
            );
        }
    }

    /**
     * Каждый category code из MAP должен иметь человекочитаемое имя в getCategoryName.
     * Если getCategoryName возвращает 'Прочие услуги Ozon' для известного кода —
     * значит getCategoryName не синхронизирован с MAP.
     */
    public function testAllCategoryCodesHaveProperNames(): void
    {
        $codes = $this->getAllCategoryCodes();

        foreach ($codes as $code) {
            $name = OzonServiceCategoryMap::getCategoryName($code);

            $this->assertNotEquals(
                'Прочие услуги Ozon',
                $name,
                sprintf(
                    'Category code "%s" не имеет имени в getCategoryName — добавь его в match()',
                    $code,
                ),
            );
        }
    }

    /**
     * isZeroMarker должен возвращать true только для нулевых маркеров.
     */
    public function testZeroMarkersAreRecognized(): void
    {
        $zeroMarkers = [
            'MarketplaceServiceItemReturnNotDelivToCustomer',
            'MarketplaceServiceItemReturnAfterDelivToCustomer',
        ];

        foreach ($zeroMarkers as $marker) {
            $this->assertTrue(
                OzonServiceCategoryMap::isZeroMarker($marker),
                sprintf('"%s" должен быть нулевым маркером', $marker),
            );
        }
    }

    /**
     * Обычные service names не должны быть нулевыми маркерами.
     */
    public function testRegularServiceNamesAreNotZeroMarkers(): void
    {
        $regular = [
            'MarketplaceServiceItemDirectFlowLogistic',
            'MarketplaceRedistributionOfAcquiringOperation',
            'MarketplaceServiceItemCrossdocking',
            'OperationMarketplaceWarehouseToWarehouseMovement',
            'MarketplaceServiceItemReplenishment',
        ];

        foreach ($regular as $serviceName) {
            $this->assertFalse(
                OzonServiceCategoryMap::isZeroMarker($serviceName),
                sprintf('"%s" не должен быть нулевым маркером', $serviceName),
            );
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Получаем все известные service names через Reflection.
     * @return string[]
     */
    private function getKnownServiceNames(): array
    {
        $ref = new \ReflectionClass(OzonServiceCategoryMap::class);
        $map = $ref->getConstants()['MAP'] ?? [];

        return array_keys($map);
    }

    /**
     * Получаем все уникальные category codes из MAP (без null).
     * @return string[]
     */
    private function getAllCategoryCodes(): array
    {
        $ref = new \ReflectionClass(OzonServiceCategoryMap::class);
        $map = $ref->getConstants()['MAP'] ?? [];

        $codes = array_filter(array_unique(array_values($map)));

        return array_values($codes);
    }
}
