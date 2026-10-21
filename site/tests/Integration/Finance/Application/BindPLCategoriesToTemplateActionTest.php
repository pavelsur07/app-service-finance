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
     * Своё дерево после сопоставления корней шаблон дополняет, а не строит второе рядом.
     */
    public function testBindingLetsTemplateCompleteOwnTreeWithoutSecondTree(): void
    {
        [$company, $own] = $this->companyWithOwnTree();

        $bound = $this->bind($company, [
            'REV_NOT_SPP_TOTAL' => $own['revenue'],
            'COGS_TOTAL' => $own['cogs'],
            'NET_PROFIT' => $own['net'],
        ]);

        self::assertSame(3, $bound);
        self::assertSame(['COGS_TOTAL', 'NET_PROFIT', 'REV_NOT_SPP_TOTAL', 'TAKEN'], $this->connection->fetchFirstColumn('SELECT code FROM pl_categories WHERE company_id = ? AND code IS NOT NULL ORDER BY code', [(string) $company->getId()]));
        self::assertSame('Выручка', $this->connection->fetchOne('SELECT name FROM pl_categories WHERE id = ?', [$own['revenue']]), 'название не меняется');

        $result = (self::getContainer()->get(ImportPLCategoryTreeAction::class))(new ImportPLCategoryTreeCommand(
            self::getContainer()->get(DefaultPLCategoryTreeProvider::class)->nodes(BusinessProfile::MARKETPLACE),
            (string) $company->getId(),
            dryRun: true,
            onlyCreate: true,
        ));
        self::assertSame([4, 3], [$result->companyRoots, $result->recognizedRoots]);
        self::assertNotContains('REV_NOT_SPP_TOTAL', array_map(static fn ($r) => $r->code, $result->created));
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
        yield 'код не из шаблона' => [static fn (array $own): array => ['REV_NOT_SPP_TOTAL' => $own['revenue'], 'NOPE' => $own['cogs']], 'Кода «NOPE» нет в шаблоне'];
        yield 'статья с кодом' => [static fn (array $own): array => ['REV_NOT_SPP_TOTAL' => $own['revenue'], 'COGS_TOTAL' => $own['coded']], 'уже код «TAKEN»'];
        yield 'другой тип' => [static fn (array $own): array => ['REV_NOT_SPP_TOTAL' => $own['revenue'], 'REV_TOTAL' => $own['cogs']], 'другого типа'];
        yield 'одна статья дважды' => [static fn (array $own): array => ['REV_NOT_SPP_TOTAL' => $own['revenue'], 'COGS_TOTAL' => $own['revenue']], 'выбрана для двух'];
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
        $categories = [
            'revenue' => PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build(),
            'cogs' => PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Себестоимость')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build(),
            'net' => PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Чистая прибыль')->withCode(null)->withType(PLCategoryType::KPI)->build(),
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
