<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Entity\ProductImport;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Страница статуса импорта: опрос включён только для незавершённого импорта, адреса — в data-*. */
final class ProductImportStatusPageTest extends WebTestCaseBase
{
    public function testPendingImportExposesPollingAttributes(): void
    {
        [$client, $import] = $this->page(static fn (ProductImport $import) => $import);

        $crawler = $client->request('GET', '/catalog/products/import/'.$import->getId());

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('#import-body[data-import-status]');
        self::assertCount(1, $root);
        self::assertSame('/api/catalog/products/import/'.$import->getId().'/status', $root->attr('data-status-url'));
        self::assertSame('/catalog/products/import', $root->attr('data-import-url'));
        self::assertNotEmpty($root->attr('data-products-url'));
        self::assertCount(1, $crawler->filter('#status-badge[data-import-status-badge]'));
    }

    public function testFinishedImportDoesNotPoll(): void
    {
        [$client, $import] = $this->page(static fn (ProductImport $import) => $import->markDone(3, 2, 1, [['row' => 2, 'reason' => 'duplicate', 'message' => 'Дубль']]));

        $crawler = $client->request('GET', '/catalog/products/import/'.$import->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-import-status]'));
        self::assertSelectorTextContains('#status-badge', 'Завершён');
        self::assertSelectorTextContains('#import-body', 'Пропущенные строки (1)');
        self::assertSelectorTextContains('#import-body tbody', 'Дубль');
    }

    /**
     * @param callable(ProductImport): ProductImport $prepare
     *
     * @return array{KernelBrowser, ProductImport}
     */
    private function page(callable $prepare): array
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('import-status-'.Uuid::uuid7()->toString().'@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $import = $prepare(new ProductImport(Uuid::uuid7()->toString(), (string) $company->getId(), 'imports/test.xlsx', 'test.xlsx'));
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->persist($import);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        return [$client, $import];
    }
}
