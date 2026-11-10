<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Query;

use App\Cash\Query\PaymentCalendarQuery;
use App\Company\Facade\CounterpartyFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PaymentCalendarQueryTest extends IntegrationTestCase
{
    public function testCalendarFiltersRetainArchivedCounterpartiesWithinCompany(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $ownParty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($company)->asArchived()->build();
        $foreignParty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($other)->build();
        foreach ([$owner, $company, $other, $ownParty, $foreignParty] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $filters = ['from' => '', 'to' => '', 'account_id' => '', 'category_id' => '', 'counterparty_id' => '', 'status' => ''];
        $context = self::getContainer()->get(PaymentCalendarQuery::class)($company, null, null, $filters);
        $ids = array_column($context['counterparties'], 'id');
        self::assertContains($ownParty->getId(), $ids);
        self::assertNotContains($foreignParty->getId(), $ids);
        self::assertSame([], self::getContainer()->get(CounterpartyFacade::class)->getSelectable((string) $company->getId()));
    }
}
