<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Кнопка «Синхронизировать за период» у Ozon-подключения ставит задачи by-day
 * (а не ходит в снятый /v3/finance/transaction/list), начало окна поднимается
 * до порога 08.09.2026.
 */
final class OzonManualSyncTest extends WebTestCaseBase
{
    public function testSyncPeriodQueuesAccrualByDayTasksFromSafeDay(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedActiveOzonConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync-period', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'sync_period'.$connection->getId()),
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-10',
        ]);

        self::assertResponseRedirects('/marketplace/connections');

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        $dates = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SyncOzonAccrualByDayMessage::class, $message);
            self::assertSame($connection->getId(), $message->connectionId);
            $dates[] = $message->date;
        }
        self::assertSame(['2026-09-10', '2026-09-09', '2026-09-08'], $dates);

        $flashes = $this->flashes($client, 'success');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('Запланировано 3 задач загрузки начислений Ozon за 08.09.2026 — 10.09.2026.', $flashes[0]);
        self::assertStringContainsString('Дни до 08.09.2026 уже загружены из прежнего источника.', $flashes[0]);
    }

    public function testSyncPeriodWithReversedDatesIsRejectedBeforeQueueing(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedActiveOzonConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync-period', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'sync_period'.$connection->getId()),
            'date_from' => '2026-09-12',
            'date_to' => '2026-09-10',
        ]);

        self::assertResponseRedirects('/marketplace/connections');

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(0, $transport->getSent());
        self::assertSame(
            ['Дата начала должна быть меньше или равна дате окончания'],
            $this->flashes($client, 'error'),
        );
    }

    public function testSyncPeriodEntirelyBeforeSafeDayQueuesNothingAndSaysWhy(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedActiveOzonConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync-period', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'sync_period'.$connection->getId()),
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-05',
        ]);

        self::assertResponseRedirects('/marketplace/connections');

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(0, $transport->getSent());
        self::assertSame(
            ['Новых задач нет: дни до 08.09.2026 уже загружены из прежнего источника.'],
            $this->flashes($client, 'success'),
        );

        $this->em()->clear();
        $reloaded = $this->em()->find(MarketplaceConnection::class, $connection->getId());
        self::assertInstanceOf(MarketplaceConnection::class, $reloaded);
        self::assertNull($reloaded->getLastSyncAt(), 'Пустое окно не должно выдавать себя за синхронизацию.');
    }

    /**
     * @return list<string>
     */
    private function flashes(KernelBrowser $client, string $type): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return array_values(array_map('strval', $session->getFlashBag()->peek($type)));
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /**
     * @return array{User, Company}
     */
    private function seedBaseData(): array
    {
        $user = UserBuilder::aUser()->withEmail('ozon-manual-sync@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }

    private function seedActiveOzonConnection(Company $company): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON);
        $connection->setApiKey('test-api-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $em = $this->em();
        $em->persist($connection);
        $em->flush();

        return $connection;
    }
}
