<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Entity\ProductImport;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Регрессия PR 2 (S3-миграция): загрузка импорта идёт через ObjectStorageInterface,
 * а не напрямую через StorageService. Проверяем, что файл сохранён по тому же пути
 * (filePath из БД) и читается обратно из хранилища.
 */
final class ProductImportUploadTest extends WebTestCaseBase
{
    public function testUploadStoresFileThroughObjectStorage(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-product-import@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId('21111111-1111-1111-1111-1111111110aa')
            ->withOwner($owner)
            ->withName('Company Product Import')
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $payload = 'fake-xlsx-bytes-'.bin2hex(random_bytes(4));
        $tmpPath = tempnam(sys_get_temp_dir(), 'imp-').'.xlsx';
        file_put_contents($tmpPath, $payload);
        $upload = new UploadedFile($tmpPath, 'products.xlsx', null, null, true);

        $client->request('POST', '/catalog/products/import', [], ['file' => $upload]);

        self::assertResponseStatusCodeSame(302);

        /** @var list<ProductImport> $imports */
        $imports = $em->getRepository(ProductImport::class)->findAll();
        self::assertCount(1, $imports);

        $import = $imports[0];
        self::assertSame('products.xlsx', $import->getOriginalName());
        self::assertStringStartsWith(sprintf('product_imports/%s/', $company->getId()), $import->getFilePath());

        // Файл действительно попал в хранилище по пути из БД и читается обратно.
        /** @var ObjectStorageInterface $storage */
        $storage = $client->getContainer()->get(ObjectStorageInterface::class);
        self::assertTrue($storage->exists($import->getFilePath()));
        self::assertSame($payload, $storage->read($import->getFilePath()));

        @unlink($tmpPath);
    }
}
