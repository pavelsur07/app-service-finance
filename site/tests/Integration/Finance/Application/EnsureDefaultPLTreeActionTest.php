<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Application;

use App\Company\Entity\Company;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class EnsureDefaultPLTreeActionTest extends IntegrationTestCase
{
    public function testAppliesTemplateToCompanyWithoutCategoriesOnce(): void
    {
        $companyId = (string) $this->createCompany()->getId();
        $action = self::getContainer()->get(EnsureDefaultPLTreeAction::class);

        self::assertSame(70, $action($companyId));
        $this->em->clear();
        self::assertSame(0, $action($companyId));

        self::assertSame(70, $this->categoryCount($companyId));
        $templateRoots = array_map(
            static fn (PLCategoryTreeNode $node): string => $node->name,
            array_values(array_filter(
                self::getContainer()->get(DefaultPLCategoryTreeProvider::class)->nodes(),
                static fn (PLCategoryTreeNode $node): bool => null === $node->parent,
            )),
        );
        self::assertSame($templateRoots, $this->connection->fetchFirstColumn(
            'SELECT name FROM pl_categories WHERE company_id = ? AND parent_id IS NULL ORDER BY sort_order',
            [$companyId],
        ));
    }

    public function testCompanyWithOwnCategoryIsLeftAsIs(): void
    {
        $company = $this->createCompany();
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Своя статья')->withCode(null)->build());
        $this->em->flush();

        self::assertSame(0, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId()));

        self::assertSame(['Своя статья'], $this->connection->fetchFirstColumn(
            'SELECT name FROM pl_categories WHERE company_id = ?',
            [(string) $company->getId()],
        ));
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        (self::getContainer()->get(EnsureDefaultPLTreeAction::class))(Uuid::uuid7()->toString());
    }

    private function createCompany(): Company
    {
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function categoryCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [$companyId]);
    }
}
