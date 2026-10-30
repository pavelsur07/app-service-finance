<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Integration\Marketplace\PlPreview\PlPreviewFixture;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class OzonPlMappingUiTest extends WebTestCaseBase
{
    private const REPAIR = '/marketplace/pl-mappings/ozon/repair';

    public function testRepairOnlyDisablesLegacyOzonRulesAndIsIdempotent(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $ids = [$this->legacy($f, AmountSource::SALE_REVENUE), $this->legacy($f, AmountSource::RETURN_REFUND)];
        $before = $this->snapshot();
        $expected = array_map(static function (array $row) use ($ids): array {
            if (in_array($row['id'], $ids, true)) {
                $row['is_active'] = false;
            }

            return $row;
        }, $before);

        $this->repair($client, (string) $f->company->getId());
        self::assertResponseRedirects();
        self::assertSame($expected, $this->snapshot());
        $client->followRedirect();
        self::assertSelectorNotExists('[data-ozon-mapping-conflict]');
        self::assertSelectorTextContains('[data-ozon-mapping-status]', 'Настроено правильно');
        $this->repair($client, (string) $f->company->getId());
        self::assertResponseRedirects();
        self::assertSame($expected, $this->snapshot());
    }

    public function testRepairWithMissingStandardReplacementDoesNotPartiallyApply(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $this->legacy($f, AmountSource::SALE_REVENUE);
        $this->legacy($f, AmountSource::RETURN_REFUND);
        $f->realization->setCode('REV_SPP_RETURNS');
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement(
            "UPDATE marketplace_sale_mappings SET is_active = false WHERE company_id = :company AND amount_source = 'return_realization'",
            ['company' => $f->company->getId()],
        );
        $before = $this->snapshot();
        $this->repair($client, (string) $f->company->getId());
        self::assertResponseRedirects();
        self::assertSame($before, $this->snapshot());
        $client->followRedirect();
        self::assertStringContainsString('Сначала настройте', (string) $client->getResponse()->getContent());
    }

    public function testRepairRequiresCsrfAndScopesToActiveCompany(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $this->legacy($f, AmountSource::SALE_REVENUE);
        $before = $this->snapshot();
        $client->request('POST', self::REPAIR, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->snapshot());
        $foreignOwner = $f->foreign->getUser();
        self::assertNotNull($foreignOwner);
        $client->loginUser($foreignOwner);
        $this->setClientSessionValue($client, 'active_company_id', $f->foreign->getId());
        $this->repair($client, (string) $f->foreign->getId());
        self::assertResponseRedirects();
        self::assertSame($before, $this->snapshot());
    }

    public function testCannotReactivateLegacyConflictByToggle(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $id = $this->legacy($f, AmountSource::SALE_REVENUE, false);
        $before = $this->snapshot();
        $client->request('POST', '/marketplace/pl-mappings/'.$id.'/toggle', [
            '_token' => $this->csrfToken($client, 'toggle'.$id),
            'redirect_marketplace' => 'ozon',
        ]);
        self::assertResponseRedirects();
        self::assertSame($before, $this->snapshot());
    }

    public function testCannotCreateLegacyConflict(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $before = $this->snapshot();
        $client->request('POST', '/marketplace/pl-mappings/create', [
            '_token' => $this->csrfToken($client, 'marketplace_pl_mappings_create'),
            'marketplace' => 'ozon', 'amount_source' => 'return_refund',
            'pl_category_id' => $f->realization->getId(), 'is_active' => '1',
            'is_negative' => '1', 'redirect_marketplace' => 'ozon',
        ]);
        self::assertResponseRedirects();
        self::assertSame($before, $this->snapshot());
    }

    public function testCannotEnableLegacyConflictByEdit(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $id = $this->legacy($f, AmountSource::RETURN_REFUND, false);
        $before = $this->snapshot();
        $client->request('POST', '/marketplace/pl-mappings/'.$id.'/edit', [
            '_token' => $this->csrfToken($client, 'marketplace_pl_mappings_edit'.$id),
            'pl_category_id' => $f->realization->getId(), 'is_active' => '1', 'is_negative' => '1',
            'redirect_marketplace' => 'ozon',
        ]);
        self::assertResponseRedirects();
        self::assertSame($before, $this->snapshot());
    }

    public function testRepairDeniesMemberWithoutWriteAccess(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $this->legacy($f, AmountSource::SALE_REVENUE);
        $user = UserBuilder::aUser()->withIndex(1189)->withRoles(['ROLE_USER'])->build();
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid4()->toString())->withCompany($f->company)->withUser($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($member);
        $this->em()->flush();
        $before = $this->snapshot();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $f->company->getId());
        $this->repair($client, (string) $f->company->getId());
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->snapshot());
    }

    public function testWildberriesToggleBehaviorIsPreserved(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $id = (string) $this->em()->getConnection()->fetchOne(
            "SELECT id FROM marketplace_sale_mappings WHERE company_id = :company AND marketplace = 'wildberries' AND amount_source = 'sale_revenue'",
            ['company' => $f->company->getId()],
        );
        $client->request('POST', '/marketplace/pl-mappings/'.$id.'/toggle', [
            '_token' => $this->csrfToken($client, 'toggle'.$id), 'redirect_marketplace' => 'wildberries',
        ]);
        self::assertResponseRedirects();
        self::assertTrue((bool) $this->em()->getConnection()->fetchOne('SELECT is_active FROM marketplace_sale_mappings WHERE id = :id', ['id' => $id]));
    }

    public function testOzonPageHasBusinessLabelsAndRepairConfirmation(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $this->legacy($f, AmountSource::SALE_REVENUE);
        $this->legacy($f, AmountSource::RETURN_REFUND);
        $before = $this->snapshot();
        $client->request('GET', '/marketplace/pl-mappings?marketplace=ozon&op=sale');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-title', 'Настройка статей Ozon');
        self::assertSelectorTextContains('[data-ozon-mapping-conflict]', 'Выручка с СПП завышена');
        self::assertSelectorExists('[data-bs-target="#modal-ozon-mapping-repair"]');
        self::assertSelectorTextContains('#modal-ozon-mapping-repair', 'Применить исправление');
        self::assertSelectorTextContains('#modal-ozon-mapping-repair', 'Продажи и возвраты с СПП будут рассчитываться только по реализации Ozon');
        self::assertSelectorNotExists('#modal-add-mapping option[value="sale_revenue"]');
        self::assertSelectorExists('#modal-add-mapping input[name="marketplace"][value="ozon"]');
        self::assertSelectorTextContains('[data-ozon-realization-status]', 'ещё не загружена');
        self::assertStringContainsString('Продажи с СПП', (string) $client->getResponse()->getContent());
        self::assertSame($before, $this->snapshot(), 'GET не должен исправлять правила.');
    }

    public function testOtherMarketplaceDoesNotShowOzonUi(): void
    {
        $client = static::createClient();
        $this->fixture($client);
        $client->request('GET', '/marketplace/pl-mappings?marketplace=wildberries&op=sale');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-title', 'Маппинг ОПиУ');
        self::assertSelectorNotExists('[data-ozon-mapping-conflict]');
        self::assertSelectorNotExists('[data-ozon-realization-status]');
        self::assertSelectorExists('#modal-add-mapping option[value="sale_revenue"]');
    }

    public function testConfirmationTokenCannotBeUsedAfterCompanySwitch(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $this->legacy($f, AmountSource::SALE_REVENUE);
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid4()->toString())
            ->withCompany($f->foreign)->withUser($f->owner)->withRole('OWNER')->build();
        $this->em()->persist($member);
        $this->em()->flush();
        $before = $this->snapshot();
        $token = $this->csrfToken($client, 'ozon_pl_mapping_repair_'.$f->company->getId());
        $this->setClientSessionValue($client, 'active_company_id', $f->foreign->getId());
        $client->request('POST', self::REPAIR, ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->snapshot());
    }

    public function testDefaultPreviewUsesOzonBusinessLabels(): void
    {
        $client = static::createClient();
        $this->fixture($client);
        $client->request('POST', '/marketplace/pl-mappings/default/preview', [
            'marketplace' => 'ozon', '_token' => $this->csrfToken($client, 'marketplace_default_sale_mapping'),
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $labels = array_column($payload['items'], 'amountSourceLabel', 'amountSource');
        self::assertSame('Продажи с СПП', $labels['sale_realization']);
        self::assertSame('Возвраты с СПП', $labels['return_realization']);
    }

    public function testValidOzonRealizationCanBeDisabledAndEnabled(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $id = (string) $this->em()->getConnection()->fetchOne(
            "SELECT id FROM marketplace_sale_mappings WHERE company_id = :company AND marketplace = 'ozon' AND amount_source = 'sale_realization'",
            ['company' => $f->company->getId()],
        );
        foreach ([false, true] as $expected) {
            $client->request('POST', '/marketplace/pl-mappings/'.$id.'/toggle', [
                '_token' => $this->csrfToken($client, 'toggle'.$id), 'redirect_marketplace' => 'ozon',
            ]);
            self::assertResponseRedirects();
            self::assertSame($expected, (bool) $this->em()->getConnection()->fetchOne('SELECT is_active FROM marketplace_sale_mappings WHERE id = :id', ['id' => $id]));
        }
    }

    public function testValidOzonSaveReportsPreviouslyDisabledRule(): void
    {
        $client = static::createClient();
        $f = $this->fixture($client);
        $old = new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $f->company, MarketplaceType::OZON, AmountSource::SALE_GROSS, $f->costs);
        $this->em()->persist($old);
        $this->em()->flush();
        $client->request('POST', '/marketplace/pl-mappings/create', [
            '_token' => $this->csrfToken($client, 'marketplace_pl_mappings_create'),
            'marketplace' => 'ozon', 'amount_source' => 'sale_gross',
            'pl_category_id' => $f->revenue->getId(), 'is_active' => '1', 'redirect_marketplace' => 'ozon',
        ]);
        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM marketplace_sale_mappings WHERE company_id = :company AND marketplace = 'ozon' AND amount_source = 'sale_gross' AND pl_category_id = :category AND is_active = true",
            ['company' => $f->company->getId(), 'category' => $f->revenue->getId()],
        ));
        self::assertFalse((bool) $this->em()->getConnection()->fetchOne('SELECT is_active FROM marketplace_sale_mappings WHERE id = :id', ['id' => $old->getId()]));
        $client->followRedirect();
        self::assertStringContainsString('Предыдущее активное правило для этого источника было деактивировано', (string) $client->getResponse()->getContent());
    }

    private function fixture(KernelBrowser $client): PlPreviewFixture
    {
        $f = new PlPreviewFixture($this->em(), 1181);
        $client->loginUser($f->owner);
        $this->setClientSessionValue($client, 'active_company_id', $f->company->getId());

        return $f;
    }

    private function legacy(PlPreviewFixture $f, AmountSource $source, bool $active = true): string
    {
        $mapping = new MarketplaceSaleMapping(Uuid::uuid4()->toString(), $f->company, MarketplaceType::OZON, $source, $f->realization);
        $mapping->setIsActive($active);
        $mapping->setIsNegative('return' === $source->getOperationType());
        $this->em()->persist($mapping);
        $this->em()->flush();

        return $mapping->getId();
    }

    private function repair(KernelBrowser $client, string $companyId): void
    {
        $client->request('POST', self::REPAIR, ['_token' => $this->csrfToken($client, 'ozon_pl_mapping_repair_'.$companyId)]);
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT id, company_id, marketplace, amount_source, pl_category_id, is_active, is_negative FROM marketplace_sale_mappings ORDER BY id');
    }
}
