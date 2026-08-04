<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller\Inventory;

use App\Marketplace\Message\ImportInventoryCostPriceMessage;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InventoryImportControllerTest extends WebTestCaseBase
{
    public function testAcceptsWbSupplierSkuImport(): void
    {
        $client = $this->authenticatedClient('wb-cost-import@example.test');

        $tmpPath = tempnam(sys_get_temp_dir(), 'wb-cost-upload-');
        if (false === $tmpPath) {
            throw new \RuntimeException('Failed to create upload fixture.');
        }
        file_put_contents($tmpPath, 'xlsx payload');

        $storagePath = null;
        try {
            $client->request(
                'POST',
                '/marketplace/inventory/import-cost-price',
                [
                    '_token' => $this->csrfToken($client, 'marketplace_inventory_import_cost_price'),
                    'effective_from' => '2026-07-29',
                    'marketplace' => 'wildberries',
                    'identifier_type' => 'supplier_sku',
                ],
                [
                    'cost_file' => new UploadedFile($tmpPath, 'wb-costs.xlsx', null, null, true),
                ],
            );

            /** @var InMemoryTransport $transport */
            $transport = $client->getContainer()->get('messenger.transport.async_pipeline');
            $sent = $transport->getSent();
            if ([] !== $sent && $sent[0]->getMessage() instanceof ImportInventoryCostPriceMessage) {
                $storagePath = $sent[0]->getMessage()->storagePath;
            }

            self::assertResponseRedirects('/marketplace/inventory');
            self::assertCount(1, $sent);

            $message = $sent[0]->getMessage();
            self::assertInstanceOf(ImportInventoryCostPriceMessage::class, $message);
            self::assertSame('wildberries', $message->marketplace);
            self::assertSame('supplier_sku', $message->identifierType);
        } finally {
            if (null !== $storagePath) {
                /** @var ObjectStorageInterface $storage */
                $storage = $client->getContainer()->get(ObjectStorageInterface::class);
                $storage->delete($storagePath);
            }
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    public function testStillAcceptsOzonSupplierSkuImport(): void
    {
        $client = $this->authenticatedClient('ozon-cost-import@example.test');

        $tmpPath = tempnam(sys_get_temp_dir(), 'ozon-cost-upload-');
        if (false === $tmpPath) {
            throw new \RuntimeException('Failed to create upload fixture.');
        }
        file_put_contents($tmpPath, 'xlsx payload');

        $storagePath = null;
        try {
            $client->request(
                'POST',
                '/marketplace/inventory/import-cost-price',
                [
                    '_token' => $this->csrfToken($client, 'marketplace_inventory_import_cost_price'),
                    'effective_from' => '2026-07-29',
                    'marketplace' => 'ozon',
                    'identifier_type' => 'supplier_sku',
                ],
                [
                    'cost_file' => new UploadedFile($tmpPath, 'ozon-costs.xlsx', null, null, true),
                ],
            );

            /** @var InMemoryTransport $transport */
            $transport = $client->getContainer()->get('messenger.transport.async_pipeline');
            $sent = $transport->getSent();
            if ([] !== $sent && $sent[0]->getMessage() instanceof ImportInventoryCostPriceMessage) {
                $storagePath = $sent[0]->getMessage()->storagePath;
            }

            self::assertResponseRedirects('/marketplace/inventory');
            self::assertCount(1, $sent);

            $message = $sent[0]->getMessage();
            self::assertInstanceOf(ImportInventoryCostPriceMessage::class, $message);
            self::assertSame('ozon', $message->marketplace);
            self::assertSame('supplier_sku', $message->identifierType);
        } finally {
            if (null !== $storagePath) {
                /** @var ObjectStorageInterface $storage */
                $storage = $client->getContainer()->get(ObjectStorageInterface::class);
                $storage->delete($storagePath);
            }
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    public function testRejectsWbMarketplaceSkuImport(): void
    {
        $client = $this->authenticatedClient('wb-cost-import-reject@example.test');

        $tmpPath = tempnam(sys_get_temp_dir(), 'wb-cost-upload-reject-');
        if (false === $tmpPath) {
            throw new \RuntimeException('Failed to create upload fixture.');
        }
        file_put_contents($tmpPath, 'xlsx payload');

        try {
            $client->request(
                'POST',
                '/marketplace/inventory/import-cost-price',
                [
                    '_token' => $this->csrfToken($client, 'marketplace_inventory_import_cost_price'),
                    'effective_from' => '2026-07-29',
                    'marketplace' => 'wildberries',
                    'identifier_type' => 'marketplace_sku',
                ],
                [
                    'cost_file' => new UploadedFile($tmpPath, 'wb-costs.xlsx', null, null, true),
                ],
            );

            self::assertResponseRedirects('/marketplace/inventory');
            $client->followRedirect();
            self::assertStringContainsString(
                'Импорт по SKU маркетплейса доступен только для Ozon.',
                (string) $client->getResponse()->getContent(),
            );
        } finally {
            unlink($tmpPath);
        }

        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_pipeline');
        self::assertCount(0, $transport->getSent());
    }

    public function testInventoryPageContainsSupplierSkuImportGuidance(): void
    {
        $client = $this->authenticatedClient('wb-cost-import-ui@example.test');

        $crawler = $client->request('GET', '/marketplace/inventory');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Артикул продавца',
            trim($crawler->filter('select[name="identifier_type"] option[value="supplier_sku"]')->text()),
        );
        self::assertStringContainsString('Артикул продавца Wildberries / vendorCode', $client->getResponse()->getContent());
        self::assertStringContainsString('регистр букв не учитывается', $client->getResponse()->getContent());
        self::assertStringContainsString('Одна цена будет применена ко всем размерам', $client->getResponse()->getContent());
        self::assertStringContainsString('отличающиеся только регистром, будут пропущены с ошибкой', $client->getResponse()->getContent());
    }

    private function authenticatedClient(string $email): KernelBrowser
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return $client;
    }
}
