<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Message\ApplyMappingBulkJobMessage;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use App\MoySklad\MessageHandler\ApplyMappingBulkJobHandler;
use App\MoySklad\MessageHandler\DiagnoseMappingsHandler;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Массовое принятие предложений из отчёта диагностики (docs/tasks/moysklad-bulk-mapping/plan.md, этап 1). */
final class BulkMappingTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testAcceptAllProposalsProcessesWholeReportInBackgroundOnce(): void
    {
        [$client, $d] = $this->seed();
        $this->readyPairs($d, 2, 29); // + пара '001' из фикстуры = 30 предложений
        $this->diagnose($d);

        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-automatic-preview]');
        $button = $client->getCrawler()->filter('[data-bulk-accept-all]');
        self::assertCount(1, $button);
        self::assertStringContainsString('30', $button->text());
        self::assertStringContainsString('по артикулу', (string) $button->attr('data-confirm-body'));
        $payload = $this->confirmPayload($button);

        $client->request('POST', (string) $button->attr('data-confirm-action'), $payload);
        self::assertResponseStatusCodeSame(303);
        $client->request('POST', (string) $button->attr('data-confirm-action'), $payload);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->rows('moysklad_mapping_bulk_jobs'), 'Повтор формы с тем же jobId — тот же пакет.');
        self::assertSame(30, $this->rows('moysklad_mapping_bulk_job_items'));
        $message = $this->lastBulkMessage();
        self::assertSame($payload['jobId'], $message->jobId);

        $client->request('GET', $this->url($d));
        self::assertSelectorTextContains('[data-bulk-job]', 'из 30');

        $this->handle($message);
        $this->handle($message);
        self::assertSame(30, $this->rows('moysklad_listing_mapping_revisions'), 'Повтор delivery не создаёт дублей.');
        self::assertSame(30, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM moysklad_listing_mapping_revisions WHERE listing_snapshot->'autoMapping'->>'previewId'=?", [$payload['jobId']]));
        $job = $this->db()->fetchAssociative('SELECT status, accepted, skipped, conflicts, failed, processed FROM moysklad_mapping_bulk_jobs');
        self::assertSame(['status' => 'completed', 'accepted' => 30, 'skipped' => 0, 'conflicts' => 0, 'failed' => 0, 'processed' => 30], $job);

        $client->request('GET', $this->url($d));
        self::assertSelectorTextContains('[data-bulk-job]', 'Принято 30');
        $report = $client->getCrawler()->filter('[data-bulk-job] a[data-bulk-report]')->attr('href');
        $client->request('GET', (string) $report);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '[data-bulk-item-listing-id]');
        $client->request('GET', (string) $report, ['status' => 'skipped']);
        self::assertSelectorNotExists('[data-bulk-item-listing-id]');
    }

    public function testChangedRowIsSkippedAndStaleReportOrActiveJobIsRejected(): void
    {
        [$client, $d] = $this->seed();
        $this->readyPairs($d, 2, 2);
        $this->diagnose($d);
        $payload = $this->startPayload($client, $d);
        $client->request('POST', $this->startUrl($d), $payload);
        self::assertResponseStatusCodeSame(303);
        $message = $this->lastBulkMessage(); // InMemoryTransport сбрасывается между запросами клиента

        $other = $this->startPayload($client, $d);
        $client->request('POST', $this->startUrl($d), $other);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertStringContainsString('Пакет уже обрабатывается', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->rows('moysklad_mapping_bulk_jobs'));

        $this->db()->executeStatement("UPDATE moysklad_products SET article='CHANGED' WHERE external_id=?", [$d['product']->getExternalId()]);
        $this->handle($message);
        $item = $this->db()->fetchAssociative('SELECT status, message FROM moysklad_mapping_bulk_job_items WHERE listing_id=?', [$d['listing']->getId()]);
        self::assertIsArray($item);
        self::assertSame('skipped', $item['status']);
        self::assertStringContainsString('изменил', (string) $item['message']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions WHERE listing_id=?', [$d['listing']->getId()]));
        self::assertSame(['accepted' => 2, 'skipped' => 1], $this->db()->fetchAssociative('SELECT accepted, skipped FROM moysklad_mapping_bulk_jobs'));

        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);
        self::assertSelectorExists('[data-bulk-accept-all][data-confirm-blocked="true"]');
        $client->request('POST', $this->startUrl($d), array_replace($other, ['jobId' => Uuid::uuid7()->toString()]));
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertStringContainsString('Отчёт устарел', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->rows('moysklad_mapping_bulk_jobs'));
    }

    public function testSelectedRowsOnlyAndReadOnlyUserIsForbidden(): void
    {
        [$client, $d] = $this->seed();
        $ids = $this->readyPairs($d, 2, 2);
        $this->diagnose($d);
        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);
        self::assertSelectorCount(3, 'input[form="bulk-selected"][name="listingIds[]"]');
        $form = $client->getCrawler()->filter('form#bulk-selected')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['jobId' => Uuid::uuid7()->toString(), 'listingIds' => [Uuid::uuid7()->toString()]]));
        self::assertResponseStatusCodeSame(422, 'Только строки-предложения отчёта.');
        self::assertSame(0, $this->rows('moysklad_mapping_bulk_jobs'));

        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['listingIds' => [$ids[0]]]));
        self::assertResponseStatusCodeSame(303);
        $this->handle($this->lastBulkMessage());
        self::assertSame(1, $this->rows('moysklad_listing_mapping_revisions'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions WHERE listing_id=?', [$ids[0]]));

        $this->loginReader($client, $d);
        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);
        self::assertSelectorNotExists('[data-bulk-accept-all]');
        self::assertSelectorNotExists('form#bulk-selected');
        $client->request('POST', $this->startUrl($d), ['scope' => 'report', 'jobId' => Uuid::uuid7()->toString()]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUndoClearsOnlyMappingsUntouchedSinceTheJobAndIsIdempotent(): void
    {
        [$client, $d] = $this->seed();
        $ids = $this->readyPairs($d, 2, 2);
        $this->diagnose($d);
        $payload = $this->startPayload($client, $d);
        $client->request('POST', $this->startUrl($d), $payload);
        $this->handle(new ApplyMappingBulkJobMessage($d['connection']->getCompanyId(), $payload['jobId']));
        // Связь первого листинга после пакета сняли вручную — отмена её не трогает.
        self::getContainer()->get(\App\MoySklad\Application\Action\ChangeListingMappingAction::class)(new \App\MoySklad\Application\Command\ChangeListingMappingCommand(
            $d['connection']->getCompanyId(), $d['actor'], $d['connection']->getId(), $ids[0], \App\MoySklad\Enum\ListingMappingOperation::CLEAR, null, null, 1, Uuid::uuid7()->toString()));

        $client->request('GET', $this->url($d).'/bulk/'.$payload['jobId']);
        self::assertResponseIsSuccessful();
        $button = $client->getCrawler()->filter('[data-bulk-undo]');
        self::assertCount(1, $button);
        $client->request('POST', (string) $button->attr('data-confirm-action'), ['_token' => (string) $button->attr('data-confirm-token')]);
        self::assertResponseStatusCodeSame(303);
        $client->request('POST', (string) $button->attr('data-confirm-action'), ['_token' => (string) $button->attr('data-confirm-token')]);
        self::assertResponseStatusCodeSame(303);
        $undo = new \App\MoySklad\Message\UndoMappingBulkJobMessage($d['connection']->getCompanyId(), $payload['jobId']);
        self::getContainer()->get(\App\MoySklad\MessageHandler\UndoMappingBulkJobHandler::class)($undo);
        self::getContainer()->get(\App\MoySklad\MessageHandler\UndoMappingBulkJobHandler::class)($undo);

        self::assertSame(['undo_status' => 'completed', 'undone' => 2, 'undo_skipped' => 1], $this->db()->fetchAssociative('SELECT undo_status, undone, undo_skipped FROM moysklad_mapping_bulk_jobs'));
        self::assertSame(3, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM moysklad_listing_mapping_revisions WHERE operation='clear'"), 'Ручная + две отмены, без дублей.');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM moysklad_listing_mapping_revisions WHERE listing_id=? AND operation='clear'", [$ids[0]]));
        $client->request('GET', $this->url($d).'/bulk/'.$payload['jobId']);
        self::assertSelectorNotExists('[data-bulk-undo]');
        self::assertSelectorTextContains('.vf-page-head__lead', 'отменён: снято 2, оставлено 1');
    }

    public function testAutoApplyStartsOneDailyCabinetJobOnlyForAllowedAuthor(): void
    {
        [$client, $d] = $this->seed();
        $this->readyPairs($d, 2, 2);
        $client->request('GET', $this->url($d));
        $form = $client->getCrawler()->filter('form[data-auto-apply-settings]')->form();
        $enabled = $form['enabled'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $enabled);
        $enabled->tick();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertTrue((bool) $this->db()->fetchOne('SELECT enabled FROM moysklad_mapping_auto_apply'));

        self::assertNotNull(self::$kernel);
        $command = new CommandTester((new Application(self::$kernel))->find('app:moysklad:mapping-auto-apply'));
        $command->execute([]);
        $command->execute([]);
        self::assertSame(1, $this->rows('moysklad_mapping_bulk_jobs'), 'Один пакет в сутки.');
        $job = $this->db()->fetchAssociative('SELECT id, origin, scope FROM moysklad_mapping_bulk_jobs');
        self::assertIsArray($job);
        self::assertSame(['auto', 'cabinet'], [$job['origin'], $job['scope']]);
        $this->handle(new ApplyMappingBulkJobMessage($d['connection']->getCompanyId(), (string) $job['id']));
        self::assertSame(['status' => 'completed', 'accepted' => 3], $this->db()->fetchAssociative('SELECT status, accepted FROM moysklad_mapping_bulk_jobs'));
        self::assertSame(3, $this->rows('moysklad_listing_mapping_revisions'), 'Без отчёта диагностики — по свежей проверке кабинета.');

        $reader = $this->loginReader($client, $d);
        $this->db()->executeStatement('DELETE FROM moysklad_mapping_bulk_job_items');
        $this->db()->executeStatement('DELETE FROM moysklad_mapping_bulk_jobs');
        $this->db()->executeStatement('UPDATE moysklad_mapping_auto_apply SET actor_user_id=?', [$reader]);
        $command->execute([]);
        self::assertSame(0, $this->rows('moysklad_mapping_bulk_jobs'), 'Автор без права записи — пакет не создаётся.');
    }

    public function testRequeueRedispatchesStalledJob(): void
    {
        [$client, $d] = $this->seed();
        $this->diagnose($d);
        $client->request('POST', $this->startUrl($d), $this->startPayload($client, $d));
        $this->transport()->reset();
        $this->db()->executeStatement("UPDATE moysklad_mapping_bulk_jobs SET heartbeat_at = heartbeat_at - INTERVAL '1 hour'");

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:moysklad:mapping-bulk:requeue'));
        self::assertSame(0, $tester->execute([]));
        self::assertCount(1, $this->transport()->getSent());
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection,company:\App\Company\Entity\Company} $d
     * @return list<string>
     */
    private function readyPairs(array $d, int $from, int $count): array
    {
        $ids = [];
        foreach (range($from, $from + $count - 1) as $i) {
            $this->em()->persist(MoySkladProductBuilder::aProduct()->forConnection($d['connection'])->withIndex($i)->withName('Товар '.$i)->withArticle('ART-'.$i)->build());
            $listing = MarketplaceListingBuilder::aListing()->forCompany($d['company'])->withIndex($i)->withMarketplace(MarketplaceType::OZON)
                ->withMarketplaceSku('OZ-'.$i)->withSupplierSku('ART-'.$i)->withName('Листинг '.$i)->build();
            $this->em()->persist($listing);
            $ids[] = $listing->getId();
        }
        $this->em()->flush();

        return $ids;
    }

    /** @return array<string,string> */
    private function confirmPayload(\Symfony\Component\DomCrawler\Crawler $button): array
    {
        /** @var array<string,string> $fields */
        $fields = json_decode((string) $button->attr('data-confirm-fields'), true, 512, \JSON_THROW_ON_ERROR);

        return ['_token' => (string) $button->attr('data-confirm-token')] + $fields;
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection} $d
     * @return array<string,string>
     */
    private function startPayload(KernelBrowser $client, array $d): array
    {
        $client->request('GET', $this->url($d), ['view' => 'queue', 'diag_status' => 'ready', 'diag_manual' => '']);

        return $this->confirmPayload($client->getCrawler()->filter('[data-bulk-accept-all]'));
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection} $d */
    private function startUrl(array $d): string
    {
        return $this->url($d).'/bulk';
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection} $d */
    private function url(array $d): string
    {
        return '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/ozon';
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection} $d */
    private function diagnose(array $d): void
    {
        self::getContainer()->get(DiagnoseMappingsHandler::class)(new DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', Uuid::uuid7()->toString()));
    }

    private function handle(ApplyMappingBulkJobMessage $message): void
    {
        self::getContainer()->get(ApplyMappingBulkJobHandler::class)($message);
    }

    private function lastBulkMessage(): ApplyMappingBulkJobMessage
    {
        $sent = array_values(array_filter(array_map(static fn ($e) => $e->getMessage(), $this->transport()->getSent()), static fn ($m) => $m instanceof ApplyMappingBulkJobMessage));
        self::assertNotSame([], $sent);
        return $sent[array_key_last($sent)];
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function rows(string $table): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    /** @param array{connection:\App\MoySklad\Entity\MoySkladConnection} $d */
    private function loginReader(KernelBrowser $client, array $d): string
    {
        $user = \App\Tests\Builders\Company\UserBuilder::aUser()->withIndex(2)->withEmail('bulk-reader@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = $this->em()->getReference(\App\Company\Entity\Company::class, $d['connection']->getCompanyId());
        self::assertInstanceOf(\App\Company\Entity\Company::class, $company);
        $role = new \App\Company\Entity\CompanyRole(Uuid::uuid7()->toString(), 'Reader', ['marketplace' => 'read'], $company);
        $member = \App\Tests\Builders\Company\CompanyMemberBuilder::aMember()->withCompany($company)->withUser($user)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $d['connection']->getCompanyId());

        return (string) $user->getId();
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
