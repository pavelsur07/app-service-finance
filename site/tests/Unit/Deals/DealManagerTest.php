<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deals;

use App\Company\Repository\CompanyMemberRepository;
use App\Deals\Exception\InvalidDealState;
use App\Deals\Repository\ChargeTypeRepository;
use App\Deals\Service\DealManager;
use App\Deals\Service\DealNumberGenerator;
use App\Deals\Service\DealTotalsCalculator;
use App\Deals\Service\Request\AddDealItemRequest;
use App\Repository\CounterpartyRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Deals\DealBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DealManagerTest extends TestCase
{
    public function testConfirmedDealCannotBeEdited(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $numberGenerator = $this->createMock(DealNumberGenerator::class);
        $counterpartyRepository = $this->createMock(CounterpartyRepository::class);
        $chargeTypeRepository = $this->createMock(ChargeTypeRepository::class);
        $companyMemberRepository = $this->createMock(CompanyMemberRepository::class);

        $dealManager = new DealManager(
            $em,
            new DealTotalsCalculator(),
            $numberGenerator,
            $counterpartyRepository,
            $chargeTypeRepository,
            $companyMemberRepository,
        );

        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $deal = DealBuilder::aDeal()->forCompany($company)->build();
        $deal->markConfirmed();

        $request = new AddDealItemRequest(
            name: 'Item',
            kind: \App\Deals\Enum\DealItemKind::GOOD,
            qty: '1.00',
            price: '10.00',
            amount: '10.00',
            lineIndex: 1,
            unit: 'pcs',
        );

        $this->expectException(InvalidDealState::class);
        $dealManager->addItem($request, $deal, $user, $company);
    }
}
