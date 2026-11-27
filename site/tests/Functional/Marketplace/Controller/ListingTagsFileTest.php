<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Application\ImportListingTagsAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Import\ListingTagsFileReader;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ListingTagsFileTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000003162';
    private const LISTING_ID = '33333333-3333-3333-3333-000000003162';

    public function testExportReturnsListingsOfCompany(): void
    {
        $client = static::createClient();
        $this->seed($client);

        $crawler = $client->request('GET', '/marketplace/listings');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="listing-tags-file-export"]'));
        self::assertCount(1, $crawler->filter('form[action="/marketplace/listings/tags-file/upload"]'));

        $client->request('GET', '/marketplace/listings/tags-file');

        self::assertResponseIsSuccessful();
        self::assertInstanceOf(BinaryFileResponse::class, $client->getResponse());
        // Файл удаляется после отправки — читаем то, что получил браузер.
        self::assertEquals([new ListingTagsFileRow(2, self::LISTING_ID, '')], $this->readExport($client));
    }

    public function testUploadShowsPreviewAndApplyAssignsTag(): void
    {
        $client = static::createClient();
        $this->seed($client);
        $crawler = $client->request('GET', '/marketplace/listings');
        $form = $crawler->filter('form[action="/marketplace/listings/tags-file/upload"]')->form();
        $this->fileField($form)->upload($this->xlsx([['listing_id', 'Теги'], [self::LISTING_ID, 'Лето']]));

        $preview = $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('добавится связей: 1', $preview->filter('[data-testid="tags-import-mode-add"]')->text());
        self::assertSame(0, $this->assignmentsCount(), 'Предпросмотр ничего не пишет');

        $client->submit($preview->selectButton('Применить')->form());

        self::assertResponseRedirects('/marketplace/listings');
        self::assertSame(1, $this->assignmentsCount());
    }

    public function testUploadRejectsNonXlsx(): void
    {
        $client = static::createClient();
        $this->seed($client);
        $crawler = $client->request('GET', '/marketplace/listings');
        $form = $crawler->filter('form[action="/marketplace/listings/tags-file/upload"]')->form();
        $csv = tempnam(sys_get_temp_dir(), 'tags_').'.csv';
        file_put_contents($csv, "listing_id;Теги\n".self::LISTING_ID.";Лето\n");
        $this->fileField($form)->upload($csv);

        $client->submit($form);

        self::assertResponseRedirects('/marketplace/listings');
        self::assertSame(0, $this->assignmentsCount());
    }

    public function testApplyWithoutCsrfIsForbidden(): void
    {
        $client = static::createClient();
        $this->seed($client);

        $client->request('POST', '/marketplace/listings/tags-file/apply', [
            'rows' => json_encode([[2, self::LISTING_ID, 'Лето']]),
            'mode' => 'add',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->assignmentsCount());
    }

    public function testExportFollowsRegistryFilter(): void
    {
        $client = static::createClient();
        [, $company] = $this->seed($client);
        $em = $this->em();
        $em->persist(MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('ozon-file-2')->withName('Носки')->build());
        $em->flush();

        $client->request('GET', '/marketplace/listings/tags-file?q=плать');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->readExport($client));
    }

    public function testExportOverLimitRedirectsWithoutFile(): void
    {
        $client = static::createClient();
        $this->seed($client);
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO marketplace_listings (id, company_id, marketplace, marketplace_sku, size, price, is_active, created_at, updated_at)
             SELECT gen_random_uuid(), ?, 'ozon', 'bulk-' || g, 'UNKNOWN', 0, true, NOW(), NOW() FROM generate_series(1, ?) g",
            [self::COMPANY_ID, ImportListingTagsAction::MAX_ROWS],
        );

        $client->request('GET', '/marketplace/listings/tags-file');

        self::assertResponseRedirects();
    }

    public function testUploadRejectsFileOverTwoMegabytes(): void
    {
        $client = static::createClient();
        $this->seed($client);
        $form = $this->uploadForm($client);
        $big = tempnam(sys_get_temp_dir(), 'tags_').'.xlsx';
        file_put_contents($big, str_repeat('x', 2 * 1024 * 1024 + 1));
        $this->fileField($form)->upload($big);

        $client->submit($form);

        self::assertResponseRedirects('/marketplace/listings');
        unlink($big);
    }

    public function testBrokenXlsxIsRejectedWithFlash(): void
    {
        $client = static::createClient();
        $this->seed($client);
        $form = $this->uploadForm($client);
        $broken = tempnam(sys_get_temp_dir(), 'tags_').'.xlsx';
        file_put_contents($broken, 'not a zip');
        $this->fileField($form)->upload($broken);

        $client->submit($form);

        self::assertResponseRedirects('/marketplace/listings');
        unlink($broken);
    }

    public function testUploadWithoutCsrfIsForbidden(): void
    {
        $client = static::createClient();
        $this->seed($client);

        $client->request('POST', '/marketplace/listings/tags-file/upload', [], ['tags_file' => new UploadedFile($this->xlsx([['listing_id', 'Теги']]), 'tags.xlsx', null, null, true)]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testReplaceModeAppliesReplacePlan(): void
    {
        $client = static::createClient();
        $this->seed($client);
        $form = $this->uploadForm($client);
        $this->fileField($form)->upload($this->xlsx([['listing_id', 'Теги'], [self::LISTING_ID, 'Лето']]));
        $preview = $client->submit($form);
        $client->submit($preview->selectButton('Применить')->form());
        self::assertSame(1, $this->assignmentsCount());

        $form = $this->uploadForm($client);
        $this->fileField($form)->upload($this->xlsx([['listing_id', 'Теги'], [self::LISTING_ID, 'Зима']]));
        $preview = $client->submit($form);
        $client->submit($preview->selectButton('Применить')->form(['mode' => 'replace']));

        self::assertResponseRedirects('/marketplace/listings');
        self::assertSame(['Зима'], $this->em()->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM marketplace_listing_tag_assignments a JOIN marketplace_listing_tags t ON t.id = a.tag_id WHERE a.listing_id = ?',
            [self::LISTING_ID],
        ));
    }

    public function testReadOnlyMemberCanExportButNotImport(): void
    {
        $client = static::createClient();
        [, $company] = $this->seed($client);
        $role = new CompanyRole(Uuid::uuid4()->toString(), 'Только чтение маркетплейсов', [Module::MARKETPLACE->value => AccessLevel::READ->value]);
        $reader = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('listing-tags-file-reader@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($reader)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        foreach ([$role, $reader, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($reader);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', '/marketplace/listings/tags-file');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/marketplace/listings/tags-file/upload');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/marketplace/listings/tags-file/apply', ['rows' => '[]', 'mode' => 'add']);
        self::assertResponseStatusCodeSame(403);
    }

    private function uploadForm(KernelBrowser $client): Form
    {
        return $client->request('GET', '/marketplace/listings')->filter('form[action="/marketplace/listings/tags-file/upload"]')->form();
    }

    /**
     * @return list<ListingTagsFileRow>
     */
    private function readExport(KernelBrowser $client): array
    {
        $file = tempnam(sys_get_temp_dir(), 'tags_').'.xlsx';
        file_put_contents($file, $client->getInternalResponse()->getContent());
        try {
            return (new ListingTagsFileReader())->read($file);
        } finally {
            unlink($file);
        }
    }

    /**
     * @return array{User, Company}
     */
    private function seed(KernelBrowser $client): array
    {
        $owner = UserBuilder::aUser()->withEmail('listing-tags-file@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $listing = MarketplaceListingBuilder::aListing()
            ->withId(self::LISTING_ID)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-file-1')
            ->withName('Платье')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($listing);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$owner, $company];
    }

    private function fileField(Form $form): FileFormField
    {
        $field = $form->get('tags_file');
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tags_').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    private function assignmentsCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE company_id = ?',
            [self::COMPANY_ID],
        );
    }
}
