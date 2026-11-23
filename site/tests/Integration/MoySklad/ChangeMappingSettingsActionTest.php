<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\ChangeMappingSettingsAction;
use App\MoySklad\Exception\ListingMappingException;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;

final class ChangeMappingSettingsActionTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testSettingsVersionChangesOnlyWhenPresetChanges(): void
    {
        $d = $this->mappingCatalog($this->em);
        $action = self::getContainer()->get(ChangeMappingSettingsAction::class);
        $company = $d['connection']->getCompanyId();
        $connection = $d['connection']->getId();
        self::assertSame(0, $action($company, $connection, false)->version);
        self::assertSame(1, $action($company, $connection, true)->version);
        self::assertSame(1, $action($company, $connection, true)->version);
        self::assertSame(2, $action($company, $connection, false)->version);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    public function testForeignConnectionCannotBeConfigured(): void
    {
        $d = $this->mappingCatalog($this->em);
        try {
            self::getContainer()->get(ChangeMappingSettingsAction::class)(Uuid::uuid7()->toString(), $d['connection']->getId(), true);
            self::fail('Foreign connection must be rejected.');
        } catch (ListingMappingException $e) {
            self::assertSame('not_found', $e->reason);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_settings'));
    }
}
