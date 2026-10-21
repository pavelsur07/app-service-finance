<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Application;

use App\Company\Entity\Company;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\BindPLCategoriesToTemplateAction;
use App\Finance\Application\Action\ImportPLCategoryTreeAction;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Exception\PLTemplateBindingException;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BindPLCategoriesToTemplateActionTest extends IntegrationTestCase
{
    /**
     * Своё дерево после сопоставления корней шаблон дополняет, а не строит второе рядом: ни одного
     * повторяющегося названия корня (регрессия ревью — «Выручка» дважды).
     */
    public function testBindingLetsTemplateCompleteOwnTreeWithoutSecondTree(): void
    {
        [$company, $own] = $this->companyWithOwnTree();

        self::assertSame(2, $this->bind($company, ['COGS_TOTAL' => $own['cogs'], 'NET_PROFIT' => $own['net']]));
        self::assertSame(['COGS_TOTAL', 'NET_PROFIT', 'TAKEN'], $this->connection->fetchFirstColumn('SELECT code FROM pl_categories WHERE company_id = ? AND code IS NOT NULL ORDER BY code', [(string) $company->getId()]));
        self::assertSame('Себестоимость', $this->connection->fetchOne('SELECT name FROM pl_categories WHERE id = ?', [$own['cogs']]), 'название не меняется');

        $result = (self::getContainer()->get(ImportPLCategoryTreeAction::class))(new ImportPLCategoryTreeCommand(
            self::getContainer()->get(DefaultPLCategoryTreeProvider::class)->nodes(BusinessProfile::MARKETPLACE),
            (string) $company->getId(),
            dryRun: false,
            onlyCreate: true,
        ));

        self::assertSame([4, 2], [$result->companyRoots, $result->recognizedRoots]);
        // Своя «Выручка» (подытог) не сопоставляется с KPI «Выручка» шаблона — и не дублируется им.
        self::assertSame(['REV_TOTAL'], array_map(static fn ($r) => $r->code, $result->similar));
        self::assertSame([], $this->connection->fetchFirstColumn(
            'SELECT name FROM pl_categories WHERE company_id = ? AND parent_id IS NULL GROUP BY name HAVING COUNT(*) > 1',
            [(string) $company->getId()],
        ));
        self::assertSame($own['cogs'], $this->connection->fetchOne("SELECT parent_id FROM pl_categories WHERE company_id = ? AND code = 'COGS_PRODUCT'", [(string) $company->getId()]));
    }

    /**
     * @param callable(array<string, string>, Company): array<string, string> $bindings
     */
    #[DataProvider('invalidBindings')]
    public function testInvalidBindingIsRejectedAndNothingWritten(callable $bindings, string $message): void
    {
        [$company, $own] = $this->companyWithOwnTree();

        try {
            $this->bind($company, $bindings($own, $company));
            self::fail('Сопоставление должно быть отклонено');
        } catch (PLTemplateBindingException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame(['TAKEN'], $this->connection->fetchFirstColumn('SELECT code FROM pl_categories WHERE company_id = ? AND code IS NOT NULL', [(string) $company->getId()]));
    }

    /**
     * @return iterable<string, array{callable(array<string, string>, Company): array<string, string>, string}>
     */
    public static function invalidBindings(): iterable
    {
        yield 'код не из шаблона' => [static fn (array $own): array => ['NET_PROFIT' => $own['net'], 'NOPE' => $own['cogs']], 'Кода «NOPE» нет в шаблоне'];
        yield 'статья с кодом' => [static fn (array $own): array => ['NET_PROFIT' => $own['net'], 'COGS_TOTAL' => $own['coded']], 'уже код «TAKEN»'];
        yield 'корень со вложенной статьёй шаблона' => [static fn (array $own): array => ['COGS_TOTAL' => $own['cogs'], 'REV_NOT_SPP_TOTAL' => $own['revenue']], 'на разных уровнях'];
        yield 'группа с формулой вместо суммы' => [static fn (array $own): array => ['NET_PROFIT' => $own['net'], 'REV_NOT_SPP_TOTAL' => $own['formula']], 'считается иначе'];
        yield 'другой тип' => [static fn (array $own): array => ['NET_PROFIT' => $own['net'], 'REV_TOTAL' => $own['cogs']], 'другого типа'];
        yield 'одна статья дважды' => [static fn (array $own): array => ['COGS_TOTAL' => $own['cogs'], 'PROFIT_DISTR_TOTAL' => $own['cogs']], 'выбрана для двух'];
        yield 'чужая статья' => [static fn (array $own): array => ['REV_NOT_SPP_TOTAL' => $own['foreign']], 'не найдена у компании'];
        yield 'не UUID' => [static fn (array $own): array => ['REV_NOT_SPP_TOTAL' => 'not-a-uuid'], 'не найдена у компании'];
    }

    public function testCodeTakenByAnotherCategoryIsRejected(): void
    {
        [$company, $own] = $this->companyWithOwnTree();
        $this->connection->update('pl_categories', ['code' => 'COGS_TOTAL'], ['id' => $own['coded']]);

        $this->expectException(PLTemplateBindingException::class);
        $this->expectExceptionMessage('Код «COGS_TOTAL» уже есть');

        $this->bind($company, ['COGS_TOTAL' => $own['cogs']]);
    }

    /**
     * Регрессия (внешнее ревью): статья, прочитанная без кода, к моменту записи получила код в
     * другом сохранении — код не перезаписывается.
     */
    public function testCodeAssignedConcurrentlyIsNotOverwritten(): void
    {
        [$company, $own] = $this->companyWithOwnTree();
        // Статья уже загружена в этот EntityManager без кода, а другое сохранение присвоило код.
        self::assertNull($this->em->find(PLCategory::class, $own['cogs'])?->getCode());
        $this->connection->update('pl_categories', ['code' => 'REV_TOTAL_OTHER'], ['id' => $own['cogs']]);

        try {
            $this->bind($company, ['COGS_TOTAL' => $own['cogs']]);
            self::fail('Код, присвоенный другим сохранением, не должен перезаписываться');
        } catch (PLTemplateBindingException $e) {
            self::assertStringContainsString('уже код «REV_TOTAL_OTHER»', $e->getMessage());
        }

        self::assertSame('REV_TOTAL_OTHER', $this->connection->fetchOne('SELECT code FROM pl_categories WHERE id = ?', [$own['cogs']]));
    }

    /**
     * @param array<string, string> $bindings
     */
    private function bind(Company $company, array $bindings): int
    {
        return (self::getContainer()->get(BindPLCategoriesToTemplateAction::class))((string) $company->getId(), $bindings);
    }

    /**
     * @return array{Company, array<string, string>}
     */
    private function companyWithOwnTree(): array
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($owner)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build();
        $formula = PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($revenue)->withName('Расчётная')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build();
        $formula->setFormula('1');
        $categories = [
            'revenue' => $revenue,
            'cogs' => PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Себестоимость')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build(),
            'net' => PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Чистая прибыль')->withCode(null)->withType(PLCategoryType::KPI)->build(),
            'formula' => $formula,
            'coded' => PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Своё')->withCode('TAKEN')->withType(PLCategoryType::SUBTOTAL)->build(),
            'foreign' => PLCategoryBuilder::aPLCategory()->forCompany($other)->withName('Выручка')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build(),
        ];
        foreach ([$owner, $company, $other, ...array_values($categories)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [$company, array_map(static fn (PLCategory $c): string => (string) $c->getId(), $categories)];
    }
}
