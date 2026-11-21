<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Message\ApplyMappingBulkJobMessage;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use App\MoySklad\MessageHandler\ApplyMappingBulkJobHandler;
use App\MoySklad\MessageHandler\DiagnoseMappingsHandler;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;

/** WB без модификаций в МойСкладе: штрихкод — основание, размер в артикуле/названии (docs/tasks/moysklad-wb-mapping/plan.md). */
final class WbMappingTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testWbListingsMatchByLinkedBarcodeArticleSizeAndNameSizeWhileBarcodeConflictBlocks(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $d = $this->mappingCatalog($this->em());
        $owner = $d['company']->getUser();
        self::assertNotNull($owner);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $d['connection']->getCompanyId());
        $company = $d['connection']->getCompanyId();

        $articleSize = $this->product(10, 'WJ-1-M', 'Брюки WJ-1 (M)');
        $this->product(11, 'WJ-2', 'Брюки (S, черный)');
        $nameSize = $this->product(12, 'WJ-2', 'Брюки (M, черный)');
        $conflict = $this->product(13, 'WJ-4-M', 'Шорты (M)');
        $l1 = $this->wb(10, 'WJ-1', 'М'); // кириллическая «М»
        $l2 = $this->wb(11, 'WJ-2', 'M');
        $l3 = $this->wb(12, 'NOPE', 'L');
        $l4 = $this->wb(13, 'WJ-4', 'M');
        $this->db()->executeStatement('UPDATE moysklad_products SET barcodes=\'["111"]\' WHERE external_id=?', [$conflict]);
        $this->barcode($d['listing']->getId(), 'ozon', '4600000000017');
        $this->barcode($l3, 'wildberries', '4600000000017');
        $this->barcode($l4, 'wildberries', '222');
        // Листинг Ozon связан вручную; тот же товар должен предлагаться и для WB.
        self::getContainer()->get(ChangeListingMappingAction::class)(new ChangeListingMappingCommand($company, $d['actor'], $d['connection']->getId(), $d['listing']->getId(),
            ListingMappingOperation::SET, 'product', $d['product']->getExternalId(), 0, Uuid::uuid7()->toString()));

        self::getContainer()->get(DiagnoseMappingsHandler::class)(new DiagnoseMappingsMessage($company, $d['connection']->getId(), 'wildberries', Uuid::uuid7()->toString()));
        $rows = $this->db()->fetchAllAssociativeIndexed("SELECT listing_id, status, payload->'decision'->'target'->>'externalId' AS target, payload->'decision'->'matchedRules' AS rules FROM moysklad_mapping_diagnostic_rows");
        self::assertSame(['ready', $articleSize, '["article_size"]'], array_values($rows[$l1]));
        self::assertSame(['ready', $nameSize, '["name_size"]'], array_values($rows[$l2]));
        self::assertSame(['ready', $d['product']->getExternalId(), '["linked_barcode"]'], array_values($rows[$l3]));
        self::assertSame('barcode_conflict', $rows[$l4]['status'], 'Штрихкоды есть с обеих сторон и расходятся — артикул с размером не перебивает.');

        $url = '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/wildberries';
        $client->request('GET', $url, ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);
        self::assertResponseIsSuccessful();
        $button = $client->getCrawler()->filter('[data-bulk-accept-all]');
        self::assertStringContainsString('(2)', $button->text(), '«Размер по названию» не входит в «Принять все».');
        self::assertStringContainsString('с размером по названию', (string) $button->attr('data-confirm-body'));
        /** @var array<string,string> $fields */
        $fields = json_decode((string) $button->attr('data-confirm-fields'), true, 512, \JSON_THROW_ON_ERROR);
        $client->request('POST', (string) $button->attr('data-confirm-action'), ['_token' => (string) $button->attr('data-confirm-token')] + $fields);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_bulk_job_items'));
        self::getContainer()->get(ApplyMappingBulkJobHandler::class)(new ApplyMappingBulkJobMessage($company, $fields['jobId']));
        self::assertSame(['accepted' => 2, 'skipped' => 0], $this->db()->fetchAssociative('SELECT accepted, skipped FROM moysklad_mapping_bulk_jobs'));
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT COUNT(DISTINCT listing_id) FROM moysklad_listing_mapping_revisions WHERE product_external_id=? AND operation='set'", [$d['product']->getExternalId()]),
            'Один товар связан и с Ozon, и с WB.');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions WHERE listing_id=?', [$l2]));

        // Строку «размер по названию» принимают вручную, отмеченной.
        self::getContainer()->get(DiagnoseMappingsHandler::class)(new DiagnoseMappingsMessage($company, $d['connection']->getId(), 'wildberries', Uuid::uuid7()->toString()));
        $client->request('GET', $url, ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);
        $form = $client->getCrawler()->filter('form#bulk-selected')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['jobId' => Uuid::uuid7()->toString(), 'listingIds' => [$l2]]));
        self::assertResponseStatusCodeSame(303);
        $job = (string) $this->db()->fetchOne("SELECT id FROM moysklad_mapping_bulk_jobs WHERE scope='selected'");
        self::getContainer()->get(ApplyMappingBulkJobHandler::class)(new ApplyMappingBulkJobMessage($company, $job));
        self::assertSame($nameSize, $this->db()->fetchOne("SELECT product_external_id::text FROM moysklad_listing_mapping_revisions WHERE listing_id=? AND operation='set'", [$l2]));
    }

    public function testAutoApplySkipsNameSizeProposals(): void
    {
        self::createClient();
        $d = $this->mappingCatalog($this->em());
        $this->product(11, 'WJ-2', 'Брюки (S)');
        $this->product(12, 'WJ-2', 'Брюки (M)');
        $l2 = $this->wb(11, 'WJ-2', 'M');
        $job = Uuid::uuid7()->toString();
        $this->db()->executeStatement("INSERT INTO moysklad_mapping_bulk_jobs (id, company_id, connection_id, marketplace, actor_user_id, origin, scope, status, created_at, heartbeat_at)
            VALUES (?, ?, ?, 'wildberries', ?, 'auto', 'cabinet', 'queued', now(), now())", [$job, $d['connection']->getCompanyId(), $d['connection']->getId(), $d['actor']]);
        self::getContainer()->get(ApplyMappingBulkJobHandler::class)(new ApplyMappingBulkJobMessage($d['connection']->getCompanyId(), $job));
        self::assertSame(['status' => 'completed', 'accepted' => 0], $this->db()->fetchAssociative('SELECT status, accepted FROM moysklad_mapping_bulk_jobs'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions WHERE listing_id=?', [$l2]));
    }

    private function product(int $index, string $article, string $name): string
    {
        $connection = $this->em()->getRepository(\App\MoySklad\Entity\MoySkladConnection::class)->findOneBy([]);
        self::assertInstanceOf(\App\MoySklad\Entity\MoySkladConnection::class, $connection);
        $product = MoySkladProductBuilder::aProduct()->forConnection($connection)->withIndex($index)->withName($name)->withArticle($article)->build();
        $this->em()->persist($product);
        $this->em()->flush();

        return $product->getExternalId();
    }

    private function wb(int $index, string $article, string $size): string
    {
        $company = $this->em()->getRepository(\App\Company\Entity\Company::class)->findOneBy([]);
        self::assertInstanceOf(\App\Company\Entity\Company::class, $company);
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withIndex($index)->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('WB-'.$index)->withSupplierSku($article)->withName('Листинг WB '.$index)->build();
        $this->em()->persist($listing);
        $this->em()->flush();
        $this->db()->executeStatement('UPDATE marketplace_listings SET size=? WHERE id=?', [$size, $listing->getId()]);

        return $listing->getId();
    }

    private function barcode(string $listingId, string $marketplace, string $barcode): void
    {
        $this->db()->executeStatement('INSERT INTO marketplace_listing_barcodes (id, listing_id, company_id, barcode, marketplace) SELECT ?, id, company_id, ?, ? FROM marketplace_listings WHERE id=?',
            [Uuid::uuid7()->toString(), $barcode, $marketplace, $listingId]);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }
}
