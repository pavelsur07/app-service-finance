<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Import\ListingTagsFileReader;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $file = tempnam(sys_get_temp_dir(), 'tags_').'.xlsx';
        file_put_contents($file, $client->getInternalResponse()->getContent());
        self::assertEquals(
            [new ListingTagsFileRow(2, self::LISTING_ID, '')],
            (new ListingTagsFileReader())->read($file),
        );
        unlink($file);
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
