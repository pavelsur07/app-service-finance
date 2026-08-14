<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance\Application;

use App\Balance\Application\CreateBalanceCategoryAction;
use App\Balance\Application\DTO\CreateBalanceCategoryCommand;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CreateBalanceCategoryActionTest extends IntegrationTestCase
{
    public function testCreatesCategoryWithCompanyId(): void
    {
        $company = $this->createCompany();

        /** @var CreateBalanceCategoryAction $action */
        $action = self::getContainer()->get(CreateBalanceCategoryAction::class);

        $id = ($action)($company->getId(), new CreateBalanceCategoryCommand(
            name: 'Деньги',
            type: BalanceCategoryType::ASSET,
            parentId: null,
            code: 'CASH',
            isVisible: true,
        ));

        $category = $this->em->find(BalanceCategory::class, $id);

        self::assertInstanceOf(BalanceCategory::class, $category);
        self::assertSame('Деньги', $category->getName());
        self::assertSame($company->getId(), $category->getCompanyId());
        self::assertSame('CASH', $category->getCode());
        self::assertSame(1, $category->getLevel());
    }

    public function testThrowsOnDuplicateCode(): void
    {
        $this->expectException(\DomainException::class);

        $company = $this->createCompany();

        /** @var CreateBalanceCategoryAction $action */
        $action = self::getContainer()->get(CreateBalanceCategoryAction::class);

        ($action)($company->getId(), new CreateBalanceCategoryCommand(
            name: 'Деньги',
            type: BalanceCategoryType::ASSET,
            parentId: null,
            code: 'CASH',
            isVisible: true,
        ));

        ($action)($company->getId(), new CreateBalanceCategoryCommand(
            name: 'Фонды',
            type: BalanceCategoryType::ASSET,
            parentId: null,
            code: 'CASH',
            isVisible: true,
        ));
    }

    private function createCompany(): Company
    {
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('balance-owner@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->withName('Balance Test Company')
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }
}
