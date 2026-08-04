<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class MarketplaceMutationSecurityTest extends WebTestCaseBase
{
    public function testConnectionTestIsNotAllowedViaGet(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', sprintf('/marketplace/connection/%s/test', $connection->getId()));

        self::assertResponseStatusCodeSame(405);
    }

    public function testConnectionTestRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/test', $connection->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectionSyncIsNotAllowedViaGet(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', sprintf('/marketplace/connection/%s/sync', $connection->getId()));

        self::assertResponseStatusCodeSame(405);
    }

    public function testConnectionSyncRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync', $connection->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectionSyncPeriodIsNotAllowedViaGet(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', sprintf('/marketplace/connection/%s/sync-period', $connection->getId()), [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
        ]);

        self::assertResponseStatusCodeSame(405);
    }

    public function testConnectionSyncPeriodRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync-period', $connection->getId()), [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSaleMappingToggleIsNotAllowedViaGet(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $mapping = $this->seedMapping($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', sprintf('/marketplace/pl-mappings/%s/toggle', $mapping->getId()));

        self::assertResponseStatusCodeSame(405);
    }

    public function testSaleMappingToggleRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $mapping = $this->seedMapping($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/pl-mappings/%s/toggle', $mapping->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testSaleMappingToggleWithValidCsrfTokenFlipsState(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $mapping = $this->seedMapping($company);
        $initialState = $mapping->isActive();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/pl-mappings/%s/toggle', $mapping->getId()), [
            '_token' => $this->csrfToken($client, 'toggle' . $mapping->getId()),
        ]);

        self::assertResponseRedirects('/marketplace/pl-mappings?op=sale&marketplace=all');

        $this->em()->clear();
        $reloaded = $this->em()->find(MarketplaceSaleMapping::class, $mapping->getId());
        self::assertNotNull($reloaded);
        self::assertSame(!$initialState, $reloaded->isActive());
    }

    public function testSaleMappingCreateRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/pl-mappings/create', [
            'marketplace' => MarketplaceType::OZON->value,
            'amount_source' => AmountSource::SALE_GROSS->value,
            'pl_category_id' => Uuid::uuid4()->toString(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSaleMappingCreateWithValidCsrfTokenPassesTokenCheck(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        // Обязательные поля не заполнены — контроллер доходит до валидации полей
        // (значит CSRF-проверка пройдена) и отвечает редиректом, а не 403.
        $client->request('POST', '/marketplace/pl-mappings/create', [
            '_token' => $this->csrfToken($client, 'marketplace_pl_mappings_create'),
        ]);

        self::assertResponseRedirects('/marketplace/pl-mappings?op=sale&marketplace=all');
    }

    public function testSaleMappingEditWithValidCsrfTokenPassesTokenCheck(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $mapping = $this->seedMapping($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/pl-mappings/%s/edit', $mapping->getId()), [
            '_token' => $this->csrfToken($client, 'marketplace_pl_mappings_edit' . $mapping->getId()),
        ]);

        self::assertResponseRedirects('/marketplace/pl-mappings?op=sale&marketplace=all');
    }

    public function testConnectionSyncWithValidCsrfTokenPassesTokenCheck(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        // Подключение неактивно — ручная синхронизация заблокирована, внешние API
        // не вызываются; редирект (а не 403) доказывает, что CSRF-проверка пройдена.
        $client->request('POST', sprintf('/marketplace/connection/%s/sync', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'sync' . $connection->getId()),
        ]);

        self::assertResponseRedirects('/marketplace');
    }

    public function testConnectionSyncPeriodWithValidCsrfTokenPassesTokenCheck(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $connection->setIsActive(true);
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);

        // Без date_from/date_to контроллер отвечает flash-ошибкой и редиректом,
        // а не 403 — CSRF-проверка пройдена, внешние API не вызываются.
        $client->request('POST', sprintf('/marketplace/connection/%s/sync-period', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'sync_period' . $connection->getId()),
        ]);

        self::assertResponseRedirects('/marketplace');
    }

    public function testProcessRealizationIsNotAllowedViaGet(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $rawDoc = $this->seedRawDocument($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', sprintf('/marketplace/raw/%s/process-realization', $rawDoc->getId()));

        self::assertResponseStatusCodeSame(405);
    }

    public function testProcessRealizationRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $rawDoc = $this->seedRawDocument($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/raw/%s/process-realization', $rawDoc->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectionCreateRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/connection/create', [
            'marketplace' => MarketplaceType::OZON->value,
            'api_key' => 'api-key',
            'client_id' => 'client-id',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectionCreateWithValidCsrfTokenPassesTokenCheck(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->seedConnection($company); // дубликат OZON SELLER — контроллер выйдет редиректом до внешней валидации
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/connection/create', [
            '_token' => $this->csrfToken($client, 'marketplace_connection_create'),
            'marketplace' => MarketplaceType::OZON->value,
            'api_key' => 'api-key',
            'client_id' => 'client-id',
        ]);

        self::assertResponseRedirects('/marketplace');
    }

    public function testPerformanceConnectionCreateRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/connection/performance/create', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectionEditRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/edit', $connection->getId()), [
            'project_direction_id' => '',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectionEditWithValidCsrfTokenSavesSettings(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/edit', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'marketplace_connection_edit' . $connection->getId()),
            'project_direction_id' => '',
        ]);

        self::assertResponseRedirects('/marketplace');
    }

    public function testSyncRealizationRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync-realization', $connection->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testSyncRealizationWithValidCsrfTokenPassesTokenCheck(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company); // неактивно — ручная синхронизация заблокирована, внешние API не вызываются
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/connection/%s/sync-realization', $connection->getId()), [
            '_token' => $this->csrfToken($client, 'sync_realization' . $connection->getId()),
        ]);

        self::assertResponseRedirects('/marketplace');
    }

    public function testReprocessRejectsPostWithoutCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/reprocess', [
            'marketplace' => MarketplaceType::OZON->value,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testReprocessWithValidCsrfTokenPassesTokenCheck(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        // Пустой marketplace — контроллер доходит до валидации полей (CSRF пройден)
        // и отвечает flash-ошибкой с редиректом, обработка не запускается.
        $client->request('POST', '/marketplace/reprocess', [
            '_token' => $this->csrfToken($client, 'marketplace_reprocess'),
        ]);

        self::assertResponseRedirects('/marketplace');
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    private function seedBaseData(): array
    {
        $user = UserBuilder::aUser()->withEmail('marketplace-security@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }

    private function seedConnection(Company $company): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
        );
        $connection->setApiKey('test-api-key');
        $connection->setClientId('test-client-id');

        $em = $this->em();
        $em->persist($connection);
        $em->flush();

        return $connection;
    }

    private function seedMapping(Company $company): MarketplaceSaleMapping
    {
        $plCategory = PLCategoryBuilder::aPLCategory()->forCompany($company)->build();
        $mapping = new MarketplaceSaleMapping(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            AmountSource::SALE_GROSS,
            $plCategory,
        );

        $em = $this->em();
        $em->persist($plCategory);
        $em->persist($mapping);
        $em->flush();

        return $mapping;
    }

    private function seedRawDocument(Company $company): MarketplaceRawDocument
    {
        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('sales_report')
            ->build();

        $em = $this->em();
        $em->persist($rawDoc);
        $em->flush();

        return $rawDoc;
    }
}
