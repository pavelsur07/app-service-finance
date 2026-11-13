<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AutoRule\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\Category\SaveCashflowCategoryAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashDraftIdentityTest extends IntegrationTestCase
{
    public function testNewCategoryAndRuleUseUuid7WithoutPersistingDrafts(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = self::getContainer()->get(SaveCashflowCategoryAction::class)->createDraft($company);
        $rule = self::getContainer()->get(SaveCashTransactionAutoRuleAction::class)->createDraft($company, Uuid::uuid7()->toString());
        self::assertSame(7, Uuid::fromString((string) $category->getId())->getVersion());
        self::assertSame(7, Uuid::fromString((string) $rule->getId())->getVersion());
        self::assertFalse($this->em->contains($category));
        self::assertFalse($this->em->contains($rule));
    }
}
