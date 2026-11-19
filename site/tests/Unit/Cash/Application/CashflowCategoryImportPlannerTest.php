<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\Category\CashflowCategoryImportPlanner;
use App\Cash\Application\DTO\CashflowCategoryImportPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Http\CashflowCategoryPage;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;

final class CashflowCategoryImportPlannerTest extends TestCase
{
    private const OPERATING = '11111111-1111-4111-8111-111111111111';
    private const RENT = '22222222-2222-4222-8222-222222222222';
    private const OFFICE = '33333333-3333-4333-8333-333333333333';
    private const SALARY = '44444444-4444-4444-8444-444444444444';
    private const NEW_ID = '0193a000-0000-7000-8000-000000000001';

    private Company $company;
    /** @var array<string, CashflowCategory> */
    private array $tree = [];

    protected function setUp(): void
    {
        $this->company = CompanyBuilder::aCompany()->build();
        $operating = $this->category(self::OPERATING, 'Операционная деятельность')->markAsSystem(CashflowCategory::CODE_OPERATING);
        $rent = $this->category(self::RENT, 'Аренда', $operating)->setSort(10)->setCode('RENT');
        $this->category(self::OFFICE, 'Офис', $rent);
        $this->category(self::SALARY, 'Зарплата', $operating)->setSort(20);
    }

    public function testUnchangedExportProducesNoChanges(): void
    {
        $plan = $this->plan($this->export());

        self::assertSame([], $plan->errors);
        self::assertSame([], $plan->items);
        self::assertSame(4, $plan->unchangedCount);
    }

    public function testChangedFieldsAreListedWithOldAndNewValues(): void
    {
        $payload = $this->export();
        $payload[0]['children'][0]['name'] = 'Аренда помещений';
        $payload[0]['children'][0]['sort'] = 30;

        $plan = $this->plan($payload);

        self::assertSame([], $plan->errors);
        self::assertCount(1, $plan->items);
        self::assertSame(self::RENT, $plan->items[0]['id']);
        self::assertFalse($plan->items[0]['new']);
        self::assertSame(
            [['label' => 'Название', 'from' => 'Аренда', 'to' => 'Аренда помещений'], ['label' => 'Порядок', 'from' => '10', 'to' => '30']],
            $plan->items[0]['changes'],
        );
        self::assertSame(3, $plan->unchangedCount);
    }

    public function testNestingMovesCategoryToNewParent(): void
    {
        $payload = $this->export();
        $office = $payload[0]['children'][0]['children'][0];
        $payload[0]['children'][0]['children'] = [];
        $payload[0]['children'][1]['children'] = [$office];

        $plan = $this->plan($payload);

        self::assertSame([], $plan->errors);
        self::assertCount(1, $plan->items);
        self::assertSame(self::SALARY, $plan->items[0]['values']['parentId']);
        self::assertSame([['label' => 'Родитель', 'from' => 'Аренда', 'to' => 'Зарплата']], $plan->items[0]['changes']);
    }

    public function testNodeWithoutIdIsCreatedWithGeneratedId(): void
    {
        $payload = $this->export();
        $payload[0]['children'][] = ['name' => 'Реклама', 'children' => [['name' => 'Контекст']]];

        $plan = $this->plan($payload);

        self::assertSame([], $plan->errors);
        self::assertCount(2, $plan->items);
        self::assertCount(2, $plan->newIds);
        [$ads, $context] = $plan->items;
        self::assertTrue($ads['new']);
        self::assertSame('Операционная деятельность / Реклама', $ads['path']);
        self::assertSame(self::OPERATING, $ads['values']['parentId']);
        self::assertSame($ads['id'], $context['values']['parentId']);
        self::assertSame('OPERATING', $context['values']['flowKind']);
    }

    public function testSuppliedNewIdsAreReusedAndAlreadyCreatedNodeIsNotCreatedAgain(): void
    {
        $payload = $this->export();
        $payload[0]['children'][] = ['name' => 'Реклама'];

        $first = $this->plan($payload, [self::NEW_ID]);
        self::assertSame(self::NEW_ID, $first->items[0]['id']);

        // Первая отправка формы уже создала статью — повтор находит её по тому же ID.
        $this->category(self::NEW_ID, 'Реклама', $this->tree[self::OPERATING]);
        $retry = $this->plan($payload, [self::NEW_ID]);

        self::assertSame([], $retry->errors);
        self::assertSame([], $retry->items);
    }

    public function testCategoriesMissingFromFileStayUntouched(): void
    {
        $payload = $this->export();
        unset($payload[0]['children'][1]);
        $payload[0]['children'] = array_values($payload[0]['children']);

        $plan = $this->plan($payload);

        self::assertSame([], $plan->errors);
        self::assertSame([], $plan->items);
    }

    public function testTopLevelFragmentKeepsItsParent(): void
    {
        $fragment = [$this->export()[0]['children'][0]];
        $fragment[0]['name'] = 'Аренда помещений';

        $plan = $this->plan($fragment);

        self::assertSame([], $plan->errors);
        self::assertCount(1, $plan->items);
        self::assertSame(self::OPERATING, $plan->items[0]['values']['parentId']);
        self::assertSame(['Название'], array_column($plan->items[0]['changes'], 'label'));
    }

    public function testRootFlowKindChangeIsDerivedForDescendants(): void
    {
        $root = $this->category('55555555-5555-4555-8555-555555555555', 'Проекты');
        $this->category('66666666-6666-4666-8666-666666666666', 'Стройка', $root);
        $payload = $this->export();
        $payload[1]['flowKind'] = 'INVESTING';
        $payload[1]['children'] = [];

        $plan = $this->plan($payload);

        self::assertSame([], $plan->errors);
        self::assertCount(2, $plan->items);
        self::assertSame('INVESTING', $plan->items[1]['values']['flowKind']);
        self::assertSame('Вид деятельности', $plan->items[1]['changes'][0]['label']);
    }

    public function testOperationTypeOfRegularCategoryCanBeChanged(): void
    {
        $payload = $this->export();
        $payload[0]['children'][1]['operationType'] = 'OUTFLOW';

        $plan = $this->plan($payload);

        self::assertSame([], $plan->errors);
        self::assertSame([['label' => 'Тип операции', 'from' => '—', 'to' => 'OUTFLOW']], $plan->items[0]['changes']);
    }

    public function testFingerprintDependsOnPlannedChanges(): void
    {
        $payload = $this->export();
        $payload[0]['children'][0]['name'] = 'Аренда помещений';
        $before = $this->plan($payload)->fingerprint;

        self::assertSame($before, $this->plan($payload)->fingerprint);

        $this->tree[self::RENT]->setName('Аренда склада');

        self::assertNotSame($before, $this->plan($payload)->fingerprint);
    }

    /**
     * @param \Closure(list<array<string, mixed>>, self): mixed $mutate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedPayloads')]
    public function testRejectedPayload(\Closure $mutate, string $expectedError): void
    {
        $plan = $this->plan($mutate($this->export(), $this));

        self::assertSame([], $plan->items);
        self::assertStringContainsString($expectedError, implode("\n", $plan->errors));
    }

    /** @return iterable<string, array{\Closure, string}> */
    public static function rejectedPayloads(): iterable
    {
        yield 'не список' => [static fn (): array => ['name' => 'x'], 'список статей'];
        yield 'id другой компании' => [static function (array $p): array {
            $p[0]['children'][0]['id'] = '99999999-9999-4999-8999-999999999999';

            return $p;
        }, 'не найдена среди статей компании'];
        yield 'повтор id' => [static function (array $p): array {
            $p[0]['children'][1]['id'] = self::RENT;

            return $p;
        }, 'встречается в файле дважды'];
        yield 'пустое название' => [static function (array $p): array {
            $p[0]['children'][0]['name'] = '  ';

            return $p;
        }, 'название'];
        yield 'неизвестный статус' => [static function (array $p): array {
            $p[0]['children'][0]['status'] = 'archived';

            return $p;
        }, 'status'];
        yield 'переименование системной' => [static function (array $p): array {
            $p[0]['name'] = 'Основная деятельность';

            return $p;
        }, 'системной'];
        yield 'системный код у обычной' => [static function (array $p): array {
            $p[0]['children'][1]['code'] = 'CF_FIN';

            return $p;
        }, 'зарезервирован'];
        yield 'новая системная' => [static function (array $p): array {
            $p[] = ['name' => 'Своя системная', 'isSystem' => true];

            return $p;
        }, 'isSystem'];
        yield 'дубль кода' => [static function (array $p): array {
            $p[0]['children'][1]['code'] = 'rent';

            return $p;
        }, 'Код RENT'];
        yield 'чужая статья ОПиУ' => [static function (array $p): array {
            $p[0]['children'][0]['plCategoryId'] = '99999999-9999-4999-8999-999999999999';

            return $p;
        }, 'Статья ОПиУ'];
        yield 'глубже пяти уровней' => [static function (array $p): array {
            $p[0]['children'][0]['children'][0]['children'] = [['name' => '4', 'children' => [['name' => '5', 'children' => [['name' => '6']]]]]];

            return $p;
        }, '5 уровней'];
        yield 'перенос ветки: глубже пяти уровней уходят только потомки' => [static function (array $p, self $test): array {
            // Аренда/Офис/Этаж переносится под статью 4-го уровня: «Аренда» встаёт на 5-й, потомки — на 6-й и 7-й.
            $test->category('55555555-5555-4555-8555-555555555555', 'Этаж', $test->tree[self::OFFICE]);
            $level3 = $test->category('66666666-6666-4666-8666-666666666666', 'Отдел', $test->tree[self::SALARY]);
            $test->category('77777777-7777-4777-8777-777777777777', 'Группа', $level3);
            $rent = $p[0]['children'][0];
            unset($rent['children']);
            $rent['parentId'] = '77777777-7777-4777-8777-777777777777';

            return [$rent];
        }, '5 уровней'];
        yield 'цикл через parentId' => [static function (array $p): array {
            $rent = $p[0]['children'][0];
            $rent['parentId'] = self::OFFICE;

            return [$rent];
        }, 'цикл'];
        yield 'перенос статьи с кодом консолидации' => [static function (array $p, self $test): array {
            $test->tree[self::OFFICE]->assignGroupCode('OP_OUT_OFFICE');
            $office = $p[0]['children'][0]['children'][0];
            $p[0]['children'][0]['children'] = [];
            $p[0]['children'][1]['children'] = [$office];

            return $p;
        }, 'консолидированная'];
        yield 'тип операции системной' => [static function (array $p): array {
            $p[0]['operationType'] = 'INFLOW';

            return $p;
        }, 'тип операции'];
        yield 'тип операции статьи с кодом консолидации' => [static function (array $p, self $test): array {
            $test->tree[self::RENT]->assignGroupCode('OP_OUT_RENT');
            $p[0]['children'][0]['operationType'] = 'INFLOW';

            return $p;
        }, 'тип операции'];
        yield 'технический корень' => [static function (array $p): array {
            $p[] = ['name' => 'Техника', 'flowKind' => 'TECHNICAL'];

            return $p;
        }, 'Технический'];
    }

    /**
     * @param list<string> $newIds
     */
    private function plan(mixed $payload, array $newIds = []): CashflowCategoryImportPlan
    {
        return (new CashflowCategoryImportPlanner())->plan(array_values($this->tree), $payload, ['77777777-7777-4777-8777-777777777777' => 'Выручка'], $newIds);
    }

    /** @return list<array<string, mixed>> */
    private function export(): array
    {
        $page = new CashflowCategoryPage();
        $roots = array_filter($this->tree, static fn (CashflowCategory $category): bool => null === $category->getParent());

        return array_values(array_map(static fn (CashflowCategory $category): array => $page->serializeCategory($category), $roots));
    }

    private function category(string $id, string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = (new CashflowCategory($id, $this->company))->setName($name)->setParent($parent);
        if (null !== $parent) {
            $category->setFlowKind($parent->getEffectiveFlowKind());
        }

        return $this->tree[$id] = $category;
    }
}
