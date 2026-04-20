<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

final class ReprocessAdRawDocumentControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-e10000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-e10000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-e10000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-e10000000002';

    public function testReprocessResetsFailedDocumentAndDispatchesMessage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async');
        $transport->reset();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-reprocess-ok@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $failed = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(10)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-10'))
            ->asFailed('Parse error on row 42')
            ->build();

        $em->persist($failed);
        $em->flush();

        $docId = $failed->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('POST', '/api/marketplace-ads/raw-documents/'.$docId.'/reprocess');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('reprocessing_dispatched', $data['status']);
        self::assertSame($docId, $data['documentId']);

        // БД: статус сброшен на DRAFT, processingError очищен.
        $em->clear();
        $refreshed = $em->getRepository(AdRawDocument::class)->find($docId);
        self::assertNotNull($refreshed);
        self::assertSame(AdRawDocumentStatus::DRAFT, $refreshed->getStatus());
        self::assertNull($refreshed->getProcessingError(), 'Ошибка прошлой попытки должна быть очищена.');

        // Messenger: ровно один ProcessAdRawDocumentMessage с правильными id.
        $envelopes = $transport->get();
        self::assertCount(1, $envelopes);
        $message = iterator_to_array($envelopes)[0]->getMessage();
        self::assertInstanceOf(ProcessAdRawDocumentMessage::class, $message);
        self::assertSame($docId, $message->adRawDocumentId);
        self::assertSame(self::COMPANY_ID, $message->companyId);
    }

    public function testReprocessReturns404WhenDocumentBelongsToOtherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async');
        $transport->reset();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-reprocess-idor@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $otherOwner = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('ads-reprocess-idor-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(self::OTHER_COMPANY_ID)
            ->withOwner($otherOwner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($otherOwner);
        $em->persist($otherCompany);
        $em->flush();

        $foreignDoc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(20)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-10'))
            ->asFailed('Parse error')
            ->build();

        $em->persist($foreignDoc);
        $em->flush();

        $foreignId = $foreignDoc->getId();

        // Залогинены как owner COMPANY_ID, пытаемся тронуть документ OTHER_COMPANY_ID.
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('POST', '/api/marketplace-ads/raw-documents/'.$foreignId.'/reprocess');

        self::assertResponseStatusCodeSame(404);

        // БД: чужой документ не тронут — остался FAILED с исходным processingError.
        $em->clear();
        $untouched = $em->getRepository(AdRawDocument::class)->find($foreignId);
        self::assertNotNull($untouched);
        self::assertSame(AdRawDocumentStatus::FAILED, $untouched->getStatus());
        self::assertSame('Parse error', $untouched->getProcessingError());

        // Messenger: ни одного dispatch'а.
        self::assertCount(0, $transport->get());
    }

    public function testReprocessReturns404ForUnknownDocumentId(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async');
        $transport->reset();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-reprocess-missing@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $nonexistentId = '99999999-9999-9999-9999-999999999999';
        $client->request('POST', '/api/marketplace-ads/raw-documents/'.$nonexistentId.'/reprocess');

        self::assertResponseStatusCodeSame(404);

        self::assertCount(0, $transport->get());
    }

    public function testReprocessAcceptsAlreadyDraftDocumentAndDispatchesMessage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async');
        $transport->reset();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-reprocess-draft@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        // Документ уже в DRAFT (например, предыдущий воркер крашнулся до markAsProcessed).
        $draft = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(30)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-11'))
            ->build();

        $em->persist($draft);
        $em->flush();

        self::assertSame(AdRawDocumentStatus::DRAFT, $draft->getStatus());

        $docId = $draft->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('POST', '/api/marketplace-ads/raw-documents/'.$docId.'/reprocess');

        self::assertResponseIsSuccessful();

        // Повторный reprocess на DRAFT-документе всё равно дожны диспатчнуть сообщение —
        // это нормальный сценарий после краша воркера.
        self::assertCount(1, $transport->get());
    }
}
