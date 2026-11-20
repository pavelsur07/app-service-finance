<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use App\MoySklad\MessageHandler\DiagnoseMappingsHandler;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Одна таблица страницы маппинга: очередь по диагностике и «Все листинги» (docs/tasks/moysklad-mappings-ui/plan.md). */
final class MappingsPageLayoutTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testWithoutDiagnosticsShowsAllListingsAndPromptsToRun(): void
    {
        [$client, $d] = $this->seed();
        $client->request('GET', $this->url($d));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-mapping-view="all"]');
        self::assertSelectorExists('[data-diagnostic-empty]');
        self::assertSelectorExists('[data-listing-id="'.$d['listing']->getId().'"]');
        self::assertSelectorNotExists('[data-diagnostic-listing-id]');
        self::assertSelectorExists('form[data-automatic-preview]');
        self::assertSelectorCount(1, 'table[data-mapping-table]');
    }

    public function testCompletedDiagnosticsOpensAttentionQueueWithCountsAndExpandableEvidence(): void
    {
        [$client, $d] = $this->seed();
        $miss = $this->listing($d, 2, 'NOPE');
        $this->diagnose($d);

        $client->request('GET', $this->url($d));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-mapping-view="queue"]');
        self::assertSelectorCount(1, 'table[data-mapping-table]');
        self::assertSelectorTextContains('[data-mapping-view-tabs] [aria-current="page"]', 'Требует внимания');
        self::assertSelectorTextContains('[data-mapping-view-tabs] [aria-current="page"] .cnt', '1');
        self::assertSelectorExists('[data-listing-id="'.$miss.'"][data-diagnostic-listing-id="'.$miss.'"]');
        self::assertSelectorNotExists('[data-listing-id="'.$d['listing']->getId().'"]');
        self::assertSelectorTextContains('[data-listing-id="'.$miss.'"] [data-mapping-reason]', 'Совпадений нет');
        $toggle = $client->getCrawler()->filter('[data-listing-id="'.$miss.'"] [data-bs-toggle="collapse"]');
        self::assertCount(1, $toggle);
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertSelectorExists('tr.collapse#'.$toggle->attr('aria-controls').' [data-mapping-evidence]');
        self::assertSelectorNotExists('form[data-automatic-preview]', 'Предпросмотр работает по странице «Все листинги».');

        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-mapping-view-tabs] [aria-current="page"]', 'Предложения');
        self::assertSelectorTextContains('[data-listing-id="'.$d['listing']->getId().'"] [data-mapping-proposal]', 'Товар');

        $client->request('GET', $this->url($d), ['q' => 'Листинг']);
        self::assertSelectorExists('[data-mapping-view="all"]');
        self::assertSelectorExists('[data-listing-id="'.$miss.'"]');
        self::assertSelectorExists('[data-listing-id="'.$d['listing']->getId().'"]');
        self::assertSelectorTextContains('[data-mapping-view-tabs] [aria-current="page"]', 'Все листинги');
    }

    public function testQueueRowShowsCurrentMappingInsteadOfDiagnosticSnapshot(): void
    {
        [$client, $d] = $this->seed();
        $this->diagnose($d);
        $connectionId = $d['connection']->getId();
        $listingId = $d['listing']->getId();
        $edit = '/moy-sklad/connections/'.$connectionId.'/mappings/ozon/'.$listingId.'/edit';
        $client->request('GET', $edit);
        $form = $client->getCrawler()->filter('form[data-set-mapping-form]')->form();
        $client->request('POST', $edit, array_replace($form->getPhpValues(), ['target' => 'product:'.$d['product']->getExternalId()]));
        self::assertResponseStatusCodeSame(303);

        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-listing-id="'.$listingId.'"] [data-mapping-status]', 'Сопоставлен');
        self::assertSelectorExists('[data-listing-id="'.$listingId.'"] [data-mapping-target]');
    }

    public function testSizeCharacteristicIsChosenOnceForDiagnosticsAndPreview(): void
    {
        [$client, $d] = $this->seed();
        $client->request('GET', $this->url($d));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-mapping-characteristic] select[name="sizeCharacteristicId"]');
        self::assertSelectorExists('form[data-diagnostic-refresh] input[type="hidden"][name="diagnosticSizeCharacteristicId"]');
        self::assertSelectorNotExists('form[data-diagnostic-refresh] select');
        self::assertSelectorExists('form[data-automatic-preview] select[name="sizeCharacteristicId"][hidden]');
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection,company:\App\Company\Entity\Company} $d */
    private function url(array $d): string
    {
        return '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/ozon';
    }

    /** @param array{company:\App\Company\Entity\Company} $d */
    private function listing(array $d, int $index, string $supplierSku): string
    {
        $listing = MarketplaceListingBuilder::aListing()->forCompany($d['company'])->withIndex($index)->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('OZ-'.$index)->withSupplierSku($supplierSku)->withName('Листинг без пары')->build();
        $this->em()->persist($listing);
        $this->em()->flush();

        return $listing->getId();
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection} $d */
    private function diagnose(array $d): void
    {
        self::getContainer()->get(DiagnoseMappingsHandler::class)(new DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', Uuid::uuid7()->toString()));
    }

    /** @return array{KernelBrowser,array{connection:\App\MoySklad\Entity\MoySkladConnection,product:\App\MoySklad\Entity\MoySkladProduct,listing:\App\Marketplace\Entity\MarketplaceListing,company:\App\Company\Entity\Company,actor:string}} */
    private function seed(): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $d = $this->mappingCatalog($this->em());
        $owner = $d['company']->getUser();
        self::assertNotNull($owner);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $d['connection']->getCompanyId());

        return [$client, $d];
    }
}
