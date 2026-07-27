<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Infrastructure\Export\CashImportTemplateXlsxWriter;
use App\Cash\Service\Import\File\HeaderAutoMapper;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Reader\XLSX\Reader;

final class CashFileImportTemplateControllerTest extends WebTestCaseBase
{
    private const URL = '/cash/import/file/template';

    /**
     * Контракт шаблона — не набор строк, а то, что заполненный по нему файл
     * раскладывается автомаппером без ручной настройки колонок.
     */
    public function testTemplateHeadersAreAutoMappedToAllImportFields(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('cash-import-template@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();

        $headers = $client->getResponse()->headers;
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $headers->get('Content-Type'),
        );
        $disposition = (string) $headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposition);
        self::assertStringContainsString('cash-import-template.xlsx', $disposition);

        $sheets = $this->readXlsxSheets((string) $client->getInternalResponse()->getContent());

        $dataSheet = $sheets['Операции ДДС'] ?? null;
        self::assertNotNull($dataSheet, 'Первым листом идёт лист для данных');
        self::assertSame(CashImportTemplateXlsxWriter::HEADERS, $dataSheet[0]);
        self::assertCount(
            1,
            $dataSheet,
            'На импортируемом листе только шапка: импорт читает первый лист целиком, забытая строка-пример стала бы операцией ДДС',
        );

        self::assertCount(3, $sheets['Пример заполнения'] ?? [], 'Примеры живут на отдельном листе');

        $mapping = (new HeaderAutoMapper())->suggest($dataSheet[0]);
        self::assertSame([
            'date' => 'Дата операции',
            'inflow' => 'Приход',
            'outflow' => 'Расход',
            'counterparty' => 'Контрагент',
            'description' => 'Назначение платежа',
            'doc_number' => 'Номер документа',
        ], $mapping);
    }

    public function testUploadPageRendersTemplateLink(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('cash-import-template-link@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/cash/import/file');

        self::assertResponseIsSuccessful();
        self::assertSame(self::URL, $crawler->filter('[data-testid="btn-import-template"]')->attr('href'));
    }

    /**
     * @return array<string, list<list<mixed>>> строки по имени листа
     */
    private function readXlsxSheets(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cash_import_template_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        $reader = new Reader();
        $reader->open($path);

        try {
            $sheets = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(
                        static fn ($cell) => $cell->getValue(),
                        $row->getCells(),
                    );
                }

                $sheets[$sheet->getName()] = $rows;
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $sheets;
    }
}
