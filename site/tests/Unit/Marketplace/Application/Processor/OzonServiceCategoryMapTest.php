<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Processor;

use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Marketplace\Domain\OzonCostCategory;
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
 * Запуск: php bin/phpunit tests/Unit/Marketplace/Application/Processor/OzonServiceCategoryMapTest.php
 */
final class OzonServiceCategoryMapTest extends TestCase
{
    /**
     * Каждый service name из OzonCostCategory должен резолвиться без warning
     * и возвращать category code с правильным именем (не fallback).
     */
    public function testAllKnownServiceNamesResolveWithoutWarning(): void
    {
        $logger = new WarningCapturingLogger();

        foreach (OzonCostCategory::all() as $category) {
            foreach ($category->serviceNames as $serviceName) {
                $logger->reset();
                $code = OzonServiceCategoryMap::resolve($serviceName, $logger);

                $this->assertFalse(
                    $logger->hasWarnings(),
                    sprintf('Service name "%s" triggered a warning — добавь в OzonCostCategory::all()', $serviceName),
                );

                $this->assertNotNull(
                    $code,
                    sprintf('Service name "%s" вернул null — только нулевые маркеры должны возвращать null', $serviceName),
                );

                $this->assertSame(
                    $category->code,
                    $code,
                    sprintf('Service name "%s" резолвится в "%s", а не в ожидаемый "%s"', $serviceName, $code, $category->code),
                );
            }

            foreach ($category->operationTypes as $operationType) {
                $logger->reset();
                $code = OzonServiceCategoryMap::resolve($operationType, $logger);

                $this->assertFalse(
                    $logger->hasWarnings(),
                    sprintf('Operation type "%s" triggered a warning — добавь в OzonCostCategory::all()', $operationType),
                );

                $this->assertNotNull(
                    $code,
                    sprintf('Operation type "%s" вернул null — только нулевые маркеры должны возвращать null', $operationType),
                );

                $this->assertSame(
                    $category->code,
                    $code,
                    sprintf('Operation type "%s" резолвится в "%s", а не в ожидаемый "%s"', $operationType, $code, $category->code),
                );
            }
        }
    }

    /**
     * Каждый category code из OzonCostCategory должен иметь человекочитаемое имя.
     * Если getCategoryName возвращает 'Прочие услуги Ozon' для известного кода —
     * значит getCategoryName не синхронизирован с OzonCostCategory.
     */
    public function testAllCategoryCodesHaveProperNames(): void
    {
        foreach (OzonCostCategory::all() as $category) {
            // ozon_other_service is the catch-all, its name IS 'Прочие услуги Ozon'
            if ($category->code === 'ozon_other_service') {
                continue;
            }

            $name = OzonServiceCategoryMap::getCategoryName($category->code);

            $this->assertNotEquals(
                'Прочие услуги Ozon',
                $name,
                sprintf(
                    'Category code "%s" не имеет имени в OzonCostCategory — добавь его в all()',
                    $category->code,
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
}
