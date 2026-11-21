<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\MoySklad\Message\ApplyMappingBulkJobMessage;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use App\MoySklad\MessageHandler\ApplyMappingBulkJobHandler;
use App\MoySklad\MessageHandler\DiagnoseMappingsHandler;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Отчёт диагностики пересчитывается сам (docs/tasks/moysklad-diagnostics-auto/plan.md). */
final class DiagnosticsAutoRefreshTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testSweepStartsMissingOrStaleReportOnceAndSkipsFreshOne(): void
    {
        [, $d] = $this->seed();

        $this->sweep();
        $first = $this->diagnoseMessages();
        self::assertCount(1, $first, 'Отчёта нет, листинги Ozon есть — запуск; у WB листингов нет — пропуск.');
        self::assertSame(['ozon', null], [$first[0]->marketplace, $first[0]->sizeCharacteristicId]);
        $this->sweep();
        $repeat = $this->diagnoseMessages();
        self::assertSame($first[0]->runId, $repeat[0]->runId, 'Повтор прохода для тех же данных — тот же runId.');

        $this->handle($first[0]);
        $this->handle($repeat[0]);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_diagnostic_runs'), 'Дубль доставки не создаёт второй расчёт.');
        $this->sweep();
        self::assertSame([], $this->diagnoseMessages(), 'Свежий отчёт не пересчитывается.');

        $this->db()->executeStatement("UPDATE moysklad_products SET article='CHANGED' WHERE external_id=?", [$d['product']->getExternalId()]);
        $this->sweep();
        $changed = $this->diagnoseMessages();
        self::assertCount(1, $changed, 'Каталог изменился — пересчёт.');
        self::assertNotSame($first[0]->runId, $changed[0]->runId);

        $this->db()->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET status='running'");
        $this->sweep();
        self::assertSame([], $this->diagnoseMessages(), 'Расчёт уже идёт.');
        $this->db()->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET requested_at = requested_at - INTERVAL '2 hours'");
        $this->sweep();
        $resumed = $this->diagnoseMessages();
        self::assertCount(1, $resumed, 'Зависший расчёт перезапускается.');
        self::assertSame($first[0]->runId, $resumed[0]->runId, 'Тот же runId — handler продолжит с курсора.');

        $this->db()->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET status='completed'");
        $this->db()->executeStatement('UPDATE moysklad_connections SET is_active=false');
        $this->sweep();
        self::assertSame([], $this->diagnoseMessages(), 'Выключенное подключение не проверяется.');
    }

    public function testSizeCharacteristicComesFromLastCompletedReport(): void
    {
        [, $d] = $this->seed();
        $this->em()->persist(\App\Tests\Builders\MoySklad\MoySkladVariantBuilder::aVariant()->forProduct($d['product'])->build());
        $this->em()->flush();
        $this->db()->executeStatement('UPDATE moysklad_variants SET characteristics=\'[{"id":"size-1","name":"Размер","value":"M"}]\'');
        $this->handle(new DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', '01900000-0000-7000-8000-000000000001', 'size-1'));
        $this->db()->executeStatement("UPDATE moysklad_products SET article='CHANGED' WHERE external_id=?", [$d['product']->getExternalId()]);

        $this->sweep();
        $sent = $this->diagnoseMessages();
        self::assertCount(1, $sent);
        self::assertSame('size-1', $sent[0]->sizeCharacteristicId);
    }

    public function testBulkJobQueuesRefreshAndPageRefreshesStaleReportByItself(): void
    {
        [$client, $d] = $this->seed();
        $this->sweep();
        $this->handle($this->diagnoseMessages()[0]);

        $client->request('GET', $this->url($d));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-mapping-autorefresh]', 'Свежий отчёт — без автообновления.');
        self::assertSelectorTextContains('form[data-diagnostic-refresh] button', 'Проверить заново');
        self::assertSelectorNotExists('[data-diagnostic-stale]');

        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);
        $button = $client->getCrawler()->filter('[data-bulk-accept-all]');
        /** @var array<string,string> $fields */
        $fields = json_decode((string) $button->attr('data-confirm-fields'), true, 512, \JSON_THROW_ON_ERROR);
        $client->request('POST', (string) $button->attr('data-confirm-action'), ['_token' => (string) $button->attr('data-confirm-token')] + $fields);
        self::assertResponseStatusCodeSame(303);
        $this->transport()->reset();
        self::getContainer()->get(ApplyMappingBulkJobHandler::class)(new ApplyMappingBulkJobMessage($d['connection']->getCompanyId(), $fields['jobId']));
        self::assertCount(1, $this->diagnoseMessages(), 'После пакета отчёт пересчитывается сразу.');

        $client->request('GET', $this->url($d));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->diagnoseMessages(), 'Открытие устаревшего отчёта ставит пересчёт.');
        self::assertSelectorExists('[data-mapping-autorefresh]');
        self::assertSelectorExists('[data-diagnostic-running]');
        self::assertSelectorNotExists('[data-diagnostic-stale]');
    }

    private function sweep(): void
    {
        $this->transport()->reset();
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:moysklad:mapping-diagnostics:refresh'));
        self::assertSame(0, $tester->execute([]));
    }

    /** @return list<DiagnoseMappingsMessage> */
    private function diagnoseMessages(): array
    {
        return array_values(array_filter(array_map(static fn ($e) => $e->getMessage(), $this->transport()->getSent()), static fn ($m) => $m instanceof DiagnoseMappingsMessage));
    }

    private function handle(DiagnoseMappingsMessage $message): void
    {
        self::getContainer()->get(DiagnoseMappingsHandler::class)($message);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection} $d */
    private function url(array $d): string
    {
        return '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/ozon';
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
