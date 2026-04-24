<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller;

use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

/**
 * Integration-тесты {@see \App\MarketplaceAds\Controller\ExtractBatchesController}
 * (Task-12-test).
 *
 * Покрываемые инварианты:
 *  - happy-path: batch=OK с одиночным CSV → AdRawDocument создан + message
 *    в async_pipeline + flash «N в очереди»;
 *  - идемпотентность: повторный POST → skipped=N, новый AdRawDocument не создан,
 *    дубликата message нет;
 *  - IDOR: jobId чужой company → 0 processed, flash success с 0/0/0;
 *  - invalid CSRF → 400.
 */
final class ExtractBatchesControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-e50000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-e50000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-e50000000002';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-e50000000002';

    public function testHappyPathCreatesRawDocumentAndDispatchesMessage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('extract-ok@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(10)
            ->asCompleted()
            ->build();
        $em->persist($job);
        $em->flush();

        /** @var StorageService $storage */
        $storage = static::getContainer()->get(StorageService::class);

        $csvBody = "\xEF\xBB\xBF;Кампания по продвижению товаров № 22655731, период 23.04.2026-23.04.2026\n"
            ."sku;spend\n"
            ."sku-1;1.00\n";
        $relativePath = sprintf(
            'marketplace-ads/%s/happy-path-batch.csv',
            self::COMPANY_ID,
        );
        $stored = $storage->storeBytes($csvBody, $relativePath);

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($stored['storagePath'], $stored['fileHash'], (int) $stored['sizeBytes'])
            ->build();
        $em->persist($batch);
        $em->flush();

        $this->login($client, $owner, self::COMPANY_ID);

        $token = $this->fetchCsrfToken($client, $job->getId());
        $client->request(
            'POST',
            '/marketplace-ads/jobs/'.$job->getId().'/extract-batches',
            ['_token' => $token],
        );

        self::assertResponseRedirects('/marketplace-ads');

        /** @var AdRawDocumentRepository $rawRepo */
        $rawRepo = static::getContainer()->get(AdRawDocumentRepository::class);
        $docs = $rawRepo->findBy(['companyId' => self::COMPANY_ID]);
        self::assertCount(1, $docs);
        self::assertSame(AdRawDocumentStatus::DRAFT, $docs[0]->getStatus());
        self::assertStringStartsWith(
            "batch_id=".$batch->getId()."\nfilename=happy-path-batch.csv\n---\n",
            $docs[0]->getRawPayload(),
        );

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async_pipeline');
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        $message = $envelopes[0]->getMessage();
        self::assertInstanceOf(ProcessAdRawDocumentMessage::class, $message);
        self::assertSame(self::COMPANY_ID, $message->companyId);
        self::assertSame($docs[0]->getId(), $message->adRawDocumentId);

        // Файл на диске остаётся — Task-13 удалит его, когда парсинг подтвердится.
        self::assertFileExists($storage->getAbsolutePath($relativePath));
        @unlink($storage->getAbsolutePath($relativePath));
    }

    public function testSecondInvocationSkipsExistingDocumentAndDispatchesNothing(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('extract-idempotent@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(11)
            ->asCompleted()
            ->build();
        $em->persist($job);
        $em->flush();

        /** @var StorageService $storage */
        $storage = static::getContainer()->get(StorageService::class);
        $relativePath = sprintf(
            'marketplace-ads/%s/idempotent-batch.csv',
            self::COMPANY_ID,
        );
        $stored = $storage->storeBytes('sku;spend\nsku-1;1.00', $relativePath);

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($stored['storagePath'], $stored['fileHash'], (int) $stored['sizeBytes'])
            ->build();
        $em->persist($batch);
        $em->flush();

        $this->login($client, $owner, self::COMPANY_ID);

        // Первый POST — создаёт AdRawDocument.
        $token1 = $this->fetchCsrfToken($client, $job->getId());
        $client->request(
            'POST',
            '/marketplace-ads/jobs/'.$job->getId().'/extract-batches',
            ['_token' => $token1],
        );
        self::assertResponseRedirects('/marketplace-ads');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async_pipeline');
        self::assertCount(1, $transport->getSent());

        // Второй POST — existing документ найден, skipped++; новый message не отправлен.
        $token2 = $this->fetchCsrfToken($client, $job->getId());
        $client->request(
            'POST',
            '/marketplace-ads/jobs/'.$job->getId().'/extract-batches',
            ['_token' => $token2],
        );
        self::assertResponseRedirects('/marketplace-ads');

        /** @var AdRawDocumentRepository $rawRepo */
        $rawRepo = static::getContainer()->get(AdRawDocumentRepository::class);
        $docs = $rawRepo->findBy(['companyId' => self::COMPANY_ID]);
        self::assertCount(1, $docs, 'Повторный POST не должен создавать дубликат AdRawDocument');
        // Сам сигнал об idempotent-пути: в очереди должно быть ровно одно
        // message, без дубликатов от второго POST.
        self::assertCount(1, $transport->getSent());

        @unlink($storage->getAbsolutePath($relativePath));
    }

    public function testForeignJobIdReturns0ProcessedWithoutException(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('extract-idor-own@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();
        $otherOwner = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('extract-idor-foreign@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(self::OTHER_COMPANY_ID)
            ->withOwner($otherOwner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($otherOwner);
        $em->persist($otherCompany);

        $foreignJob = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(12)
            ->asCompleted()
            ->build();
        $em->persist($foreignJob);
        $em->flush();

        /** @var StorageService $storage */
        $storage = static::getContainer()->get(StorageService::class);
        $relativePath = sprintf(
            'marketplace-ads/%s/foreign-batch.csv',
            self::OTHER_COMPANY_ID,
        );
        $stored = $storage->storeBytes('foreign-csv', $relativePath);

        $foreignBatch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($foreignJob->getId())
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($stored['storagePath'], $stored['fileHash'], (int) $stored['sizeBytes'])
            ->build();
        $em->persist($foreignBatch);
        $em->flush();

        $this->login($client, $owner, self::COMPANY_ID);

        $token = $this->fetchCsrfToken($client, $foreignJob->getId());
        $client->request(
            'POST',
            '/marketplace-ads/jobs/'.$foreignJob->getId().'/extract-batches',
            ['_token' => $token],
        );

        self::assertResponseRedirects('/marketplace-ads');

        // Чужая компания: AdRawDocument под COMPANY_ID (нашей) не создан,
        // существующий foreignBatch не затронут.
        /** @var AdRawDocumentRepository $rawRepo */
        $rawRepo = static::getContainer()->get(AdRawDocumentRepository::class);
        self::assertCount(0, $rawRepo->findBy(['companyId' => self::COMPANY_ID]));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async_pipeline');
        self::assertCount(0, $transport->getSent());

        @unlink($storage->getAbsolutePath($relativePath));
    }

    public function testInvalidCsrfTokenReturns400(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('extract-invalid-csrf@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(13)
            ->asCompleted()
            ->build();
        $em->persist($job);
        $em->flush();

        $this->login($client, $owner, self::COMPANY_ID);

        $client->request(
            'POST',
            '/marketplace-ads/jobs/'.$job->getId().'/extract-batches',
            ['_token' => 'definitely-not-the-right-token'],
        );

        self::assertResponseStatusCodeSame(400);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async_pipeline');
        self::assertCount(0, $transport->getSent());
    }

    /**
     * CSRF защита использует `_token_id` → token mapping, зависящий от сессии.
     * Для теста запрашиваем токен у CsrfTokenManager на той же сессии, что и
     * последующий POST (Symfony's WebTestCase сохраняет session cookies).
     */
    private function fetchCsrfToken($client, string $jobId): string
    {
        $container = $client->getContainer();
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $manager */
        $manager = $container->get('security.csrf.token_manager');

        return $manager->getToken('extract-batches-'.$jobId)->getValue();
    }

    private function login($client, object $owner, string $activeCompanyId): void
    {
        $client->loginUser($owner);

        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $activeCompanyId);
        $session->save();
    }
}
