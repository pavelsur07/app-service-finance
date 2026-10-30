<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
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

        $this->repair($client);
        self::assertResponseRedirects();
        self::assertSame($expected, $this->snapshot());
        $this->repair($client);
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
        $this->repair($client);
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
        $this->repair($client);
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
        $user = \App\Tests\Builders\Company\UserBuilder::aUser()->withIndex(1189)->withRoles(['ROLE_USER'])->build();
        $member = \App\Tests\Builders\Company\CompanyMemberBuilder::aMember()->withId(Uuid::uuid4()->toString())->withCompany($f->company)->withUser($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($member);
        $this->em()->flush();
        $before = $this->snapshot();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $f->company->getId());
        $this->repair($client);
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

    private function repair(KernelBrowser $client): void
    {
        $client->request('POST', self::REPAIR, ['_token' => $this->csrfToken($client, 'ozon_pl_mapping_repair')]);
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT id, company_id, marketplace, amount_source, pl_category_id, is_active, is_negative FROM marketplace_sale_mappings ORDER BY id');
    }
}
