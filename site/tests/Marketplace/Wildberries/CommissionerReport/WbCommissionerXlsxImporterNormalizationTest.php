<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Wildberries\CommissionerReport;

use App\Entity\Company;
use App\Entity\User;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;
use App\Marketplace\Wildberries\Service\CommissionerReport\WbCommissionerXlsxImporter;
use App\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WbCommissionerXlsxImporterNormalizationTest extends KernelTestCase
{
    public function testNormalizationForAcquiringAndLogistics(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var StorageService $storageService */
        $storageService = $container->get(StorageService::class);
        /** @var WbCommissionerXlsxImporter $importer */
        $importer = $container->get(WbCommissionerXlsxImporter::class);

        $em->createQuery('DELETE FROM App\\Marketplace\\Wildberries\\Entity\\WildberriesReportDetail d')->execute();
        $em->createQuery('DELETE FROM App\\Marketplace\\Wildberries\\Entity\\WildberriesCommissionerXlsxReport r')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('wb-importer@example.com');
        $user->setPassword('secret');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('WB Test');

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        $fixturePath = $this->createFixture();
        $storageRelativePath = 'tests/wb_commissioner_weekly.xlsx';
        $storageAbsolutePath = $storageService->getAbsolutePath($storageRelativePath);

        $storageService->ensureDir(dirname($storageRelativePath));
        copy($fixturePath, $storageAbsolutePath);
        @unlink($fixturePath);

        $report = new WildberriesCommissionerXlsxReport(Uuid::uuid4()->toString(), $company, new \DateTimeImmutable());
        $report->setPeriodStart(new \DateTimeImmutable('2024-01-01'));
        $report->setPeriodEnd(new \DateTimeImmutable('2024-01-31'));
        $report->setOriginalFilename('wb_commissioner_weekly.xlsx');
        $report->setStoragePath($storageRelativePath);
        $report->setFileHash(hash_file('sha256', $storageAbsolutePath) ?: '');

        $em->persist($report);
        $em->flush();

        $importer->import($report);

        $fileHash = $report->getFileHash();
        $acquiringRrdId = $this->generateRrdId($fileHash, 1);
        $logisticsRrdId = $this->generateRrdId($fileHash, 2);

        /** @var WildberriesReportDetail|null $acquiringDetail */
        $acquiringDetail = $em->getRepository(WildberriesReportDetail::class)->findOneBy(['rrdId' => $acquiringRrdId]);
        /** @var WildberriesReportDetail|null $logisticsDetail */
        $logisticsDetail = $em->getRepository(WildberriesReportDetail::class)->findOneBy(['rrdId' => $logisticsRrdId]);

        self::assertNotNull($acquiringDetail);
        self::assertSame('Эквайринг', $acquiringDetail->getSupplierOperName());
        self::assertSame('Интернет-эквайринг', $acquiringDetail->getDocTypeName());

        self::assertNotNull($logisticsDetail);
        self::assertSame('Логистика', $logisticsDetail->getSupplierOperName());
        self::assertSame('Складская логистика', $logisticsDetail->getDocTypeName());
    }

    private function generateRrdId(string $fileHash, int $rowIndex): int
    {
        $hash = sha1($fileHash.':'.$rowIndex);
        $hex = substr($hash, 0, 15);
        $rrdId = (int) base_convert($hex, 16, 10);

        if ($rrdId < 0 || $rrdId > PHP_INT_MAX) {
            throw new \RuntimeException('Generated rrdId is out of bigint range.');
        }

        return $rrdId;
    }

    private function createFixture(): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'wb_commissioner_');
        if (false === $tempPath) {
            throw new \RuntimeException('Failed to create temporary file.');
        }

        $filePath = $tempPath.'.xlsx';
        rename($tempPath, $filePath);

        $headers = [
            'Услуги по доставке товара покупателю',
            'Эквайринг/Комиссии за организацию платежей',
            'Вознаграждение Вайлдберриз (ВВ), без НДС',
            'НДС с Вознаграждения Вайлдберриз',
            'Возмещение за выдачу и возврат товаров на ПВЗ',
            'Хранение',
            'Возмещение издержек по перевозке/по складским операциям с товаром',
            'Удержания',
            'Обоснование для оплаты',
            'Тип документа',
            'Виды логистики, штрафов и корректировок ВВ',
            'Тип платежа за Эквайринг/Комиссии за организацию платежей',
            'Дата продажи',
            'Дата заказа покупателем',
        ];

        $rows = [
            $headers,
            [
                '10.00',
                '2.50',
                '100.00',
                '20.00',
                '5.00',
                '1.00',
                '3.00',
                '0.00',
                'Эквайринг',
                'Акт',
                'Логистика WB',
                'Интернет-эквайринг',
                '2024-01-15',
                '2024-01-10',
            ],
            [
                '11.00',
                '0.00',
                '90.00',
                '18.00',
                '4.00',
                '2.00',
                '6.00',
                '1.00',
                'Логистика',
                'Счет',
                'Складская логистика',
                'Безналичный эквайринг',
                '2024-02-01',
                '2024-01-28',
            ],
        ];

        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($filePath);
        foreach ($rows as $row) {
            $writer->addRow(WriterEntityFactory::createRowFromArray($row));
        }
        $writer->close();

        return $filePath;
    }
}
