<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Controller;

use App\Company\Entity\User;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

final class SnapshotRequestControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-a90000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-a90000000001';

    public function testValidCsrfRequestsSnapshotAndRedirectsWithSuccessFlash(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedOwnerAndCompany('inventory-request-ok@example.test');
        $this->persistActiveOzonConnection($company->getId(), $company);
        $this->login($client, $owner, self::COMPANY_ID);

        $transport = $this->getSyncTransport($client);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('inventory_snapshots_request')->getValue();

        $client->request('POST', '/inventory/snapshots/request', ['_token' => $token]);

        self::assertResponseRedirects('/inventory/snapshots');
        self::assertCount(1, $transport->getSent());

        $sessionRepo = static::getContainer()->get('doctrine')->getRepository(InventorySnapshotSession::class);
        self::assertCount(1, $sessionRepo->findBy(['companyId' => self::COMPANY_ID]));

        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Задача синхронизации остатков запущена.');
    }

    public function testInvalidCsrfRedirectsWithDangerFlashAndDoesNotDispatch(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedOwnerAndCompany('inventory-request-csrf@example.test');
        $this->persistActiveOzonConnection($company->getId(), $company);
        $this->login($client, $owner, self::COMPANY_ID);

        $transport = $this->getSyncTransport($client);

        $client->request('POST', '/inventory/snapshots/request', ['_token' => 'invalid-token']);

        self::assertResponseRedirects('/inventory/snapshots');
        self::assertCount(0, $transport->getSent());

        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Неверный CSRF-токен.');
    }

    public function testNoActiveConnectionShowsWarningFlash(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner] = $this->seedOwnerAndCompany('inventory-request-no-connection@example.test');
        $this->login($client, $owner, self::COMPANY_ID);

        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('inventory_snapshots_request')->getValue();
        $transport = $this->getSyncTransport($client);

        $client->request('POST', '/inventory/snapshots/request', ['_token' => $token]);

        self::assertResponseRedirects('/inventory/snapshots');
        self::assertCount(0, $transport->getSent());

        $client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Нет активного Ozon-подключения');
    }

    public function testActiveSessionExistsShowsAlreadyRunningWarning(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->seedOwnerAndCompany('inventory-request-already-running@example.test');
        $this->persistActiveOzonConnection($company->getId(), $company);
        $this->persistActiveSession();
        $this->login($client, $owner, self::COMPANY_ID);

        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('inventory_snapshots_request')->getValue();

        $client->request('POST', '/inventory/snapshots/request', ['_token' => $token]);

        self::assertResponseRedirects('/inventory/snapshots');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'Синхронизация уже выполняется.');
    }

    public function testRouteRequiresAuthenticatedOwnerWithActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('POST', '/inventory/snapshots/request', ['_token' => 'noop']);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $user = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('inventory-request-not-owner@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user);
        $client->request('POST', '/inventory/snapshots/request', ['_token' => 'noop']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function seedOwnerAndCompany(string $email): array
    {
        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail($email)
            ->asCompanyOwner()
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$owner, $company];
    }

    private function login(KernelBrowser $client, User $user, string $companyId): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();
    }

    private function persistActiveOzonConnection(string $connectionId, \App\Company\Entity\Company $company): void
    {
        $connection = new MarketplaceConnection(
            id: $connectionId,
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-api-key');
        $connection->setClientId('1000');
        $connection->setIsActive(true);

        $this->em()->persist($connection);
        $this->em()->flush();
    }

    private function persistActiveSession(): void
    {
        $session = new InventorySnapshotSession(
            companyId: self::COMPANY_ID,
            source: MarketplaceType::OZON,
            triggerType: \App\Inventory\Enum\SnapshotTriggerType::Manual,
            triggeredBy: self::OWNER_ID,
        );

        $this->em()->persist($session);
        $this->em()->flush();
    }

    private function getSyncTransport(KernelBrowser $client): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');

        return $transport;
    }
}
