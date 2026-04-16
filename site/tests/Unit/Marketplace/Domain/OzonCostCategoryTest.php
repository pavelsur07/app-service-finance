<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Domain;

use App\Marketplace\Domain\OzonCostCategory;
use PHPUnit\Framework\TestCase;

/**
 * Контрактный тест OzonCostCategory.
 *
 * Гарантирует целостность справочника:
 * - нет дублей кодов, service_name, operation_type
 * - все поля заполнены
 * - widgetGroup / xlsxGroup из фиксированного набора
 *
 * Если кто-то добавит дубль или опечатку в группе — тест упадёт.
 *
 * Запуск: php bin/phpunit tests/Unit/Marketplace/Domain/OzonCostCategoryTest.php
 */
final class OzonCostCategoryTest extends TestCase
{
    /**
     * Каждый код уникален.
     */
    public function testNoDuplicateCodes(): void
    {
        $codes = [];
        foreach (OzonCostCategory::all() as $c) {
            $this->assertArrayNotHasKey(
                $c->code,
                $codes,
                sprintf('Дублирующийся code: "%s"', $c->code),
            );
            $codes[$c->code] = true;
        }
    }

    /**
     * Каждый service_name встречается ровно один раз во всём справочнике.
     */
    public function testNoDuplicateServiceNames(): void
    {
        $seen = [];
        foreach (OzonCostCategory::all() as $c) {
            foreach ($c->serviceNames as $name) {
                $this->assertArrayNotHasKey(
                    $name,
                    $seen,
                    sprintf(
                        'service_name "%s" дублируется: в "%s" и "%s"',
                        $name,
                        $seen[$name] ?? '?',
                        $c->code,
                    ),
                );
                $seen[$name] = $c->code;
            }
        }
    }

    /**
     * Каждый operation_type встречается ровно один раз во всём справочнике.
     */
    public function testNoDuplicateOperationTypes(): void
    {
        $seen = [];
        foreach (OzonCostCategory::all() as $c) {
            foreach ($c->operationTypes as $opType) {
                $this->assertArrayNotHasKey(
                    $opType,
                    $seen,
                    sprintf(
                        'operation_type "%s" дублируется: в "%s" и "%s"',
                        $opType,
                        $seen[$opType] ?? '?',
                        $c->code,
                    ),
                );
                $seen[$opType] = $c->code;
            }
        }
    }

    /**
     * Ни один ключ не встречается одновременно в serviceNames и operationTypes
     * разных (или одной) категории.
     */
    public function testNoOverlapBetweenServiceNamesAndOperationTypes(): void
    {
        $serviceNames = [];
        $operationTypes = [];

        foreach (OzonCostCategory::all() as $c) {
            foreach ($c->serviceNames as $name) {
                $serviceNames[$name] = $c->code;
            }
            foreach ($c->operationTypes as $opType) {
                $operationTypes[$opType] = $c->code;
            }
        }

        $overlap = array_intersect_key($serviceNames, $operationTypes);
        $this->assertEmpty(
            $overlap,
            sprintf('Ключи пересекаются между serviceNames и operationTypes: %s', implode(', ', array_keys($overlap))),
        );
    }

    /**
     * Каждый код имеет непустое имя.
     */
    public function testEveryCodeHasNonEmptyName(): void
    {
        foreach (OzonCostCategory::all() as $c) {
            $this->assertNotEmpty(
                $c->name,
                sprintf('Код "%s" имеет пустое имя', $c->code),
            );
        }
    }

    /**
     * Каждый код имеет непустой code.
     */
    public function testEveryCodeHasNonEmptyCode(): void
    {
        foreach (OzonCostCategory::all() as $c) {
            $this->assertNotEmpty($c->code, 'Найден элемент с пустым code');
        }
    }

    /**
     * widgetGroup принадлежит фиксированному набору (5 групп).
     */
    public function testWidgetGroupBelongsToKnownSet(): void
    {
        $allowedGroups = [
            'Вознаграждение',
            'Услуги доставки и FBO',
            'Услуги партнёров',
            'Продвижение и реклама',
            'Другие услуги и штрафы',
        ];

        foreach (OzonCostCategory::all() as $c) {
            $this->assertContains(
                $c->widgetGroup,
                $allowedGroups,
                sprintf('Код "%s" имеет неизвестный widgetGroup: "%s"', $c->code, $c->widgetGroup),
            );
        }
    }

    /**
     * xlsxGroup принадлежит фиксированному набору (7 групп).
     */
    public function testXlsxGroupBelongsToKnownSet(): void
    {
        $allowedGroups = [
            'Вознаграждение Ozon',
            'Продвижение и реклама',
            'Услуги доставки',
            'Услуги FBO',
            'Услуги партнёров',
            'Другие услуги и штрафы',
            'Компенсации и декомпенсации',
        ];

        foreach (OzonCostCategory::all() as $c) {
            $this->assertContains(
                $c->xlsxGroup,
                $allowedGroups,
                sprintf('Код "%s" имеет неизвестный xlsxGroup: "%s"', $c->code, $c->xlsxGroup),
            );
        }
    }

    /**
     * findByCode возвращает корректную категорию.
     */
    public function testFindByCodeReturnsCorrectCategory(): void
    {
        foreach (OzonCostCategory::all() as $c) {
            $found = OzonCostCategory::findByCode($c->code);
            $this->assertNotNull($found, sprintf('findByCode("%s") вернул null', $c->code));
            $this->assertSame($c->code, $found->code);
            $this->assertSame($c->name, $found->name);
        }
    }

    /**
     * findByCode возвращает null для неизвестного кода.
     */
    public function testFindByCodeReturnsNullForUnknown(): void
    {
        $this->assertNull(OzonCostCategory::findByCode('nonexistent_code'));
    }

    /**
     * findByServiceName возвращает корректную категорию для каждого service name.
     */
    public function testFindByServiceNameReturnsCorrectCategory(): void
    {
        foreach (OzonCostCategory::all() as $c) {
            foreach ($c->serviceNames as $name) {
                $found = OzonCostCategory::findByServiceName($name);
                $this->assertNotNull($found, sprintf('findByServiceName("%s") вернул null', $name));
                $this->assertSame(
                    $c->code,
                    $found->code,
                    sprintf('findByServiceName("%s") вернул "%s", ожидается "%s"', $name, $found->code, $c->code),
                );
            }
        }
    }

    /**
     * findByOperationType возвращает корректную категорию для каждого operation type.
     */
    public function testFindByOperationTypeReturnsCorrectCategory(): void
    {
        foreach (OzonCostCategory::all() as $c) {
            foreach ($c->operationTypes as $opType) {
                $found = OzonCostCategory::findByOperationType($opType);
                $this->assertNotNull($found, sprintf('findByOperationType("%s") вернул null', $opType));
                $this->assertSame(
                    $c->code,
                    $found->code,
                    sprintf('findByOperationType("%s") вернул "%s", ожидается "%s"', $opType, $found->code, $c->code),
                );
            }
        }
    }

    /**
     * byCode возвращает все категории.
     */
    public function testByCodeContainsAllCategories(): void
    {
        $byCode = OzonCostCategory::byCode();
        $all    = OzonCostCategory::all();

        $this->assertCount(count($all), $byCode);
    }
}
