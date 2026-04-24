<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdBatchPlanner;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Message\LoadOzonAdStatisticsRangeMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

/**
 * End-to-end тесты `POST /api/marketplace-ads/ozon/load-range` после
 * переключения на cron-driven pipeline (Task-11.9a).
 *
 * Проверяют:
 *  - happy path (10 дней) → 200 + jobId + Planner вызван + НИ одного
 *    сообщения в `async_ads`-транспорте (старый Messenger не задействован);
 *  - period > 62 дней → 400 с понятным русским сообщением, Planner не вызван;
 *  - reversed dates (dateFrom > dateTo) → 400, Planner не вызван.
 */
final class OzonAdLoadRangeControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-a10000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-a10000000001';

    public function testHappyPathInvokesPlannerAndDoesNotDispatchLegacyMessage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $this->seedOwnerCompanyWithConnection($em);

        /** @var AdBatchPlanner&MockObject $plannerMock */
        $plannerMock = $this->createMock(AdBatchPlanner::class);
        $plannerMock->expects(self::once())
            ->method('planBatchesForJob')
            ->with(
                self::isType('string'),
                self::COMPANY_ID,
                self::isInstanceOf(\DateTimeImmutable::class),
                self::isInstanceOf(\DateTimeImmutable::class),
            )
            ->willReturn(2);
        $client->getContainer()->set(AdBatchPlanner::class, $plannerMock);

        $this->loginAsOwner($client);

        $client->request(
            'POST',
            '/api/marketplace-ads/ozon/load-range',
            [],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['dateFrom' => '2026-03-01', 'dateTo' => '2026-03-10'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('jobId', $data);

        // Job персистирован со status=RUNNING (Finalizer ждёт именно RUNNING).
        $em->clear();
        $job = $em->find(AdLoadJob::class, $data['jobId']);
        self::assertNotNull($job);
        self::assertSame(self::COMPANY_ID, $job->getCompanyId());
        self::assertSame(AdLoadJobStatus::RUNNING, $job->getStatus());

        // Старый Messenger-путь НЕ задействован: в async_ads-транспорте пусто.
        $transport = $this->getAsyncAdsTransport($client);
        self::assertSame(0, $this->countLoadRangeMessages($transport));
    }

    public function testReturns400ForPeriodExceeding62Days(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $this->seedOwnerCompanyWithConnection($em);

        /** @var AdBatchPlanner&MockObject $plannerMock */
        $plannerMock = $this->createMock(AdBatchPlanner::class);
        $plannerMock->expects(self::never())->method('planBatchesForJob');
        $client->getContainer()->set(AdBatchPlanner::class, $plannerMock);

        $this->loginAsOwner($client);

        // 63 дня включительно (01.01..04.03), заведомо в прошлом.
        $client->request(
            'POST',
            '/api/marketplace-ads/ozon/load-range',
            [],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['dateFrom' => '2026-01-01', 'dateTo' => '2026-03-04'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('message', $data);
        self::assertMatchesRegularExpression('/превышает лимит Ozon.*62/u', (string) $data['message']);

        // Ни одного сохранённого AdLoadJob — validator сработал ДО persist.
        $jobsCount = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_ad_load_jobs WHERE company_id = :c',
            ['c' => self::COMPANY_ID],
        );
        self::assertSame(0, $jobsCount);
    }

    public function testReturns400ForReversedDates(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $this->seedOwnerCompanyWithConnection($em);

        /** @var AdBatchPlanner&MockObject $plannerMock */
        $plannerMock = $this->createMock(AdBatchPlanner::class);
        $plannerMock->expects(self::never())->method('planBatchesForJob');
        $client->getContainer()->set(AdBatchPlanner::class, $plannerMock);

        $this->loginAsOwner($client);

        $client->request(
            'POST',
            '/api/marketplace-ads/ozon/load-range',
            [],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['dateFrom' => '2026-03-15', 'dateTo' => '2026-03-01'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
    }

    private function seedOwnerCompanyWithConnection(\Doctrine\ORM\EntityManagerInterface $em): void
    {
        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ozon-ads-range@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );
        $connection->setApiKey('test-client-secret');
        $connection->setClientId('test-client-id@advertising.performance.ozon.ru');
        $em->persist($connection);
        $em->flush();
    }

    private function loginAsOwner(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $owner = $this->em()->getRepository(\App\Company\Entity\User::class)->find(self::OWNER_ID);
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();
    }

    private function getAsyncAdsTransport(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_ads');

        return $transport;
    }

    private function countLoadRangeMessages(InMemoryTransport $transport): int
    {
        $n = 0;
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof LoadOzonAdStatisticsRangeMessage) {
                ++$n;
            }
        }

        return $n;
    }
}
