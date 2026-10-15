<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\EnsureCashflowSystemCategoriesAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class EnsureCashflowSystemCategoriesActionTest extends IntegrationTestCase
{
    public function testCreatesSystemCategoriesOnceAndIsIdempotent(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('ensure-system@example.com')->withPasswordHash('p')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        $action = self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class);

        $action((string) $company->getId());
        $action((string) $company->getId());
        $this->em->clear();

        $codes = array_map(
            static fn (CashflowCategory $category): ?string => $category->getSystemCode(),
            $this->em->getRepository(CashflowCategory::class)->findBy(['company' => (string) $company->getId()]),
        );
        sort($codes);
        self::assertSame(['CF_FIN', 'CF_INV', 'CF_OP', 'CF_TECH', 'CF_TECH_IN', 'CF_TECH_OUT', 'CF_UNALLOC'], $codes);
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        (self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class))(Uuid::uuid4()->toString());
    }
}
