<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deals;

use App\Deals\Entity\Deal;
use App\Deals\Enum\DealAdjustmentType;
use App\Deals\Enum\DealChannel;
use App\Deals\Enum\DealItemKind;
use App\Deals\Enum\DealType;
use App\Deals\Service\DealManager;
use App\Deals\Service\Request\AddDealAdjustmentRequest;
use App\Deals\Service\Request\AddDealItemRequest;
use App\Deals\Service\Request\CreateDealRequest;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class DealAdjustmentFlowTest extends WebTestCaseBase
{
    public function testConfirmedDealAllowsAdjustmentButRejectsItemChanges(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();
        $dealManager = static::getContainer()->get(DealManager::class);

        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        $createRequest = new CreateDealRequest(
            type: DealType::SALE,
            channel: DealChannel::SHOP,
            recognizedAt: new \DateTimeImmutable('2024-02-01'),
        );

        $deal = $dealManager->createDeal($createRequest, $user, $company);
        $dealId = $deal->getId();
        $companyId = $company->getId();

        self::assertTrue($deal->isDraft());
        self::assertNotNull($dealId);
        self::assertNotNull($companyId);

        $itemRequest = new AddDealItemRequest(
            name: 'Widget',
            kind: DealItemKind::GOOD,
            qty: '2.00',
            price: '15.00',
            amount: '30.00',
            lineIndex: 1,
            unit: 'pcs',
        );

        $dealManager->addItem($itemRequest, $deal, $user, $company);

        $itemId = null;
        foreach ($deal->getItems() as $item) {
            $itemId = $item->getId();
            break;
        }

        self::assertNotNull($itemId);

        $dealManager->confirmDeal($deal, $user, $company);

        $adjustmentRequest = new AddDealAdjustmentRequest(
            recognizedAt: new \DateTimeImmutable('2024-02-02'),
            amount: '5.00',
            type: DealAdjustmentType::CORRECTION,
            comment: 'Correction',
        );

        $dealManager->addAdjustment($adjustmentRequest, $deal, $user, $company);

        $em->clear();
        $dealFromDb = $em->getRepository(Deal::class)->find($dealId);

        self::assertNotNull($dealFromDb);
        self::assertTrue($dealFromDb->isConfirmed());
        self::assertCount(1, $dealFromDb->getAdjustments());

        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();

        $tokenManager = $client->getContainer()->get('security.csrf.token_manager');
        $token = $tokenManager->getToken('deal_item_remove'.$itemId)->getValue();

        $client->request('POST', sprintf('/deals/%s/items/%s/remove', $dealId, $itemId), [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(409);
    }
}
