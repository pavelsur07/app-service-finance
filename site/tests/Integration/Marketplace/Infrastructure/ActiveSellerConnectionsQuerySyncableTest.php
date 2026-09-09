<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Infrastructure;

use App\Marketplace\Application\RecordConnectionAuthResultAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * `executeSyncable()` против `execute()`.
 *
 * Разделение существует потому, что один и тот же реестр читают и команды,
 * идущие в API маркетплейса, и команды, работающие по локальным данным.
 * Сломанный ключ должен останавливать первых и не касаться вторых.
 */
final class ActiveSellerConnectionsQuerySyncableTest extends IntegrationTestCase
{
    private const COMPANY_HEALTHY = '11111111-1111-1111-1111-0d0000000001';
    private const COMPANY_BROKEN = '11111111-1111-1111-1111-0d0000000002';

    private ActiveSellerConnectionsQuery $query;
    private RecordConnectionAuthResultAction $recordAuthResult;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(ActiveSellerConnectionsQuery::class);
        $this->recordAuthResult = self::getContainer()->get(RecordConnectionAuthResultAction::class);
    }

    public function testSyncableExcludesBrokenConnectionWhileFullListingKeepsIt(): void
    {
        $healthy = $this->seedConnection(self::COMPANY_HEALTHY, 'syncable-healthy@example.test', 1);
        $broken = $this->seedConnection(self::COMPANY_BROKEN, 'syncable-broken@example.test', 2);

        $this->breakConnection(self::COMPANY_BROKEN, $broken);

        $syncableIds = array_column($this->query->executeSyncable(), 'id');
        self::assertContains($healthy, $syncableIds);
        self::assertNotContains($broken, $syncableIds, 'Сломанный ключ обязан выпасть из выборки крона');

        // Полный реестр не меняется: пересборка ОПиУ и прочие локальные
        // команды не должны терять компанию из-за проблемы аутентификации.
        $allIds = array_column($this->query->execute(), 'id');
        self::assertContains($healthy, $allIds);
        self::assertContains($broken, $allIds);
    }

    public function testConnectionReturnsToSyncableListAfterSuccessfulAuth(): void
    {
        $connectionId = $this->seedConnection(self::COMPANY_BROKEN, 'syncable-recover@example.test', 1);
        $this->breakConnection(self::COMPANY_BROKEN, $connectionId);

        self::assertNotContains($connectionId, array_column($this->query->executeSyncable(), 'id'));

        $this->recordAuthResult->recordSuccess(self::COMPANY_BROKEN, $connectionId);

        self::assertContains($connectionId, array_column($this->query->executeSyncable(), 'id'));
    }

    private function breakConnection(string $companyId, string $connectionId): void
    {
        for ($i = 0; $i < RecordConnectionAuthResultAction::AUTH_FAILURE_THRESHOLD; ++$i) {
            $this->recordAuthResult->recordFailure($companyId, $connectionId);
        }
    }

    private function seedConnection(string $companyId, string $email, int $index): string
    {
        $connectionId = sprintf('22222222-2222-4222-8222-0d000000000%d', $index);

        $owner = UserBuilder::aUser()->withIndex($index)->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();

        $connection = new MarketplaceConnection(
            $connectionId,
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();

        return $connectionId;
    }
}
