<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Ingestion\OzonAccrualTypeBuilder;
use App\Tests\Builders\Ingestion\OzonAccrualTypeGroupBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class IngestionOzonAccrualTypesAdminTest extends WebTestCaseBase
{
    private const PAGE = '/admin/ingestion/external-categories';

    public function testNonAdminCannotOpenPage(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withEmail('user@example.test')->build();
        $this->em()->persist($user);
        $this->em()->flush();

        $client->loginUser($user, 'admin');
        $client->request('GET', self::PAGE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminConfirmsQueuedType(): void
    {
        $client = $this->adminClient();
        $category = $this->unknownType127();

        $crawler = $client->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ozon_analytics_plus', $crawler->filter('.card')->text());

        $form = $crawler->filter('form[action$="/ozon-types/127/confirm"]')->form([
            'label' => 'Аналитика Plus',
            'group_code' => 'promotion',
        ]);
        $client->submit($form);

        self::assertResponseRedirects(self::PAGE);
        self::assertSame('Продвижение и реклама', $this->em()->getConnection()->fetchOne(
            'SELECT canonical_group FROM ingest_external_category_mappings WHERE external_category_id = :id',
            ['id' => $category->getId()],
        ));
    }

    public function testConfirmWithForgedTokenIsRejected(): void
    {
        $client = $this->adminClient();
        $this->unknownType127();

        $client->request('POST', self::PAGE.'/ozon-types/127/confirm', ['_token' => 'forged', 'label' => 'X', 'group_code' => 'promotion']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminResolvesDispute(): void
    {
        $client = $this->adminClient();
        $typeGroup = OzonAccrualTypeGroupBuilder::aTypeGroup()->withTypeId(74)->withGroupCode('promotion')->build();
        $typeGroup->recordDispute('partners', 5, Uuid::uuid7()->toString());
        $this->em()->persist($typeGroup);
        $this->em()->flush();

        $crawler = $client->request('GET', self::PAGE);
        $client->submit($crawler->filter('form[action$="/ozon-type-groups/74/dispute"]')->selectButton('Принять новую')->form());

        self::assertResponseRedirects(self::PAGE);
        self::assertSame('partners', $this->em()->getConnection()->fetchOne('SELECT group_code FROM ingest_ozon_accrual_type_groups WHERE type_id = 74'));
    }

    public function testAdminDismissesDisputeAndForgedTokensAreRejected(): void
    {
        $client = $this->adminClient();
        $typeGroup = OzonAccrualTypeGroupBuilder::aTypeGroup()->withTypeId(52)->withGroupCode('promotion')->build();
        $typeGroup->recordDispute('other', 2, Uuid::uuid7()->toString());
        $this->em()->persist($typeGroup);
        $this->em()->flush();

        $client->request('POST', self::PAGE.'/ozon-type-groups/52/dispute', ['_token' => 'forged', 'decision' => 'accept']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', self::PAGE.'/ozon-details-report', ['_token' => 'forged', 'company_id' => Uuid::uuid7()->toString()]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', self::PAGE);
        $client->submit($crawler->filter('form[action$="/ozon-type-groups/52/dispute"]')->selectButton('Оставить')->form());

        self::assertResponseRedirects(self::PAGE);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT group_code, disputed_group_code FROM ingest_ozon_accrual_type_groups WHERE type_id = 52');
        self::assertSame(['group_code' => 'promotion', 'disputed_group_code' => null], $row);
    }

    public function testManualMappingWithGroupOutsideListShowsError(): void
    {
        $client = $this->adminClient();
        $category = $this->unknownType127();

        $crawler = $client->request('GET', self::PAGE);
        $token = $crawler->filter(sprintf('form[action$="/mapping/%s"] input[name="_token"]', $category->getId()))->attr('value');
        $client->request('POST', self::PAGE.'/mapping/'.$category->getId(), [
            '_token' => $token,
            'canonical_code' => 'ozon_x',
            'canonical_label' => 'X',
            'canonical_group' => 'Произвольная группа',
            'transaction_type' => 'other',
            'mapping_status' => 'active',
            'known' => '1',
            'sort_order' => '9000',
        ]);

        self::assertResponseRedirects(self::PAGE);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ingest_external_category_mappings'));
    }

    public function testAdminRunsReportImportDryRun(): void
    {
        $client = $this->adminClient();
        $path = sys_get_temp_dir().'/ozon-admin-'.bin2hex(random_bytes(6)).'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Период: 01.08.2026-31.08.2026']));
        $writer->addRow(Row::fromValues(['Дата начисления', 'Группа услуг', 'Тип начисления', 'SKU', 'Сумма итого, руб.']));
        $writer->addRow(Row::fromValues(['01.08.2026', 'Услуги партнёров', 'Страхование', 1.0, -1.0]));
        $writer->close();

        try {
            $crawler = $client->request('GET', self::PAGE);
            $form = $crawler->filter('form[action$="/ozon-details-report"]')->selectButton('Dry-run')->form();
            $form['company_id'] = Uuid::uuid7()->toString();
            $reportField = $form['report'];
            self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\FileFormField::class, $reportField);
            $reportField->upload($path);
            $crawler = $client->submit($form);

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Dry-run импорта отчёта', $crawler->text());
            self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_type_groups'));
        } finally {
            unlink($path);
        }
    }

    private function adminClient(): KernelBrowser
    {
        $client = static::createClient();
        $admin = UserBuilder::aUser()->withEmail('admin@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        $client->loginUser($admin, 'admin');

        return $client;
    }

    private function unknownType127(): ExternalCategory
    {
        $companyId = Uuid::uuid7()->toString();
        $em = $this->em();
        $em->persist(OzonAccrualTypeBuilder::aType()->withTypeId(127)->withName('AnalyticsPlus')->withDescription(null)->build());
        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: 'non_item',
            normalizedKey: 'type:127',
            externalTypeId: '127',
            status: ExternalCategoryStatus::NEW,
        );
        $em->persist($category);
        $em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $companyId,
            shopRef: $companyId,
            source: IngestSource::OZON,
            externalId: 'ozon:accrual-by-day:1:non_item_fee:type-127',
            externalUpdatedAt: new \DateTimeImmutable('2026-10-02 03:00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::OTHER,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(-5600000, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-10-01 00:00:00'),
            rawRecordId: Uuid::uuid7()->toString(),
            sourceData: ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY, '_ingestion_type_id' => '127', '_ozon_category_known' => false],
        ));
        $em->flush();
        $em->clear();

        return $category;
    }
}
