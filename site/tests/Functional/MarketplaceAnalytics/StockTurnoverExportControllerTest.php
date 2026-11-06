<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class StockTurnoverExportControllerTest extends WebTestCaseBase
{
    use StockTurnoverFixtures;

    private const URL = '/marketplace-analytics/stock-turnover/export.xlsx';

    public function testExportReturnsXlsxWithHeaderAndRows(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-export@example.test', '11111111-1111-1111-1111-111111118801');
        $this->seedStandardSet($company);
        $this->login($client, $owner, $company);

        $sheets = $this->download($client, self::URL.'?period=60&date='.$this->date->format('Y-m-d'));

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(
            sprintf('filename="stock-turnover-%s-60d.xlsx"', $this->date->format('Y-m-d')),
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );

        $rows = $sheets['Оборачиваемость'];
        self::assertSame(
            ['Листинг ID', 'Товар', 'SKU', 'Артикул продавца', 'Источник', 'Остаток', 'Продано за период', 'Продаж в день', 'Дней запаса', 'Себестоимость', 'В деньгах', 'Скорость продаж'],
            $rows[0],
        );
        self::assertCount(5, $rows);
        self::assertSame('Худи серое', $rows[1][1]);
        self::assertSame('свыше 90 дней', $rows[1][11]);
        self::assertEquals(741600, $rows[1][10]);
        self::assertSame('Кепка', $rows[4][1]);
        self::assertSame('', $rows[4][10]);

        $summary = $sheets['Сводка'];
        self::assertEquals(['В товаре, ₽', 772400], [$summary[0][0], $summary[0][1]]);
        self::assertEquals(['Заморожено, ₽', 743600], [$summary[1][0], $summary[1][1]]);
        self::assertSame('Доля замороженного, %', $summary[2][0]);
        self::assertSame('Скорость продаж «до 30 дней», ₽', $summary[3][0]);
        self::assertSame(['Листингов без себестоимости', 1], [$summary[8][0], $summary[8][1]]);
    }

    public function testExportRespectsBucketFilter(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-export-bucket@example.test', '11111111-1111-1111-1111-111111118802');
        $this->seedStandardSet($company);
        $this->login($client, $owner, $company);

        $sheets = $this->download($client, self::URL.'?bucket=fast&date='.$this->date->format('Y-m-d'));

        self::assertCount(2, $sheets['Оборачиваемость']);
        self::assertSame('Футболка чёрная', $sheets['Оборачиваемость'][1][1]);
        // Сводка — по всем строкам фильтра, не по корзине.
        self::assertEquals(772400, $sheets['Сводка'][0][1]);
    }

    public function testExportKeepsFormulaLookingTextAsStrings(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany('turnover-export-formula@example.test', '11111111-1111-1111-1111-111111118803');
        $listing = $this->listing($company, 8899, '=HYPERLINK("http://evil")', '=1+1');
        $this->snapshot($company, $listing, '5.000');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        $client->request('GET', self::URL.'?date='.$this->date->format('Y-m-d'));
        self::assertResponseStatusCodeSame(200);

        // В xlsx формула — элемент <f>; текст, начинающийся с «=», должен остаться строкой.
        $path = tempnam(sys_get_temp_dir(), 'turnover_formula_');
        self::assertNotFalse($path);
        file_put_contents($path, $client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        self::assertStringNotContainsString('<f>', $sheetXml);

        $sheets = $this->download($client, self::URL.'?date='.$this->date->format('Y-m-d'));
        self::assertSame('=1+1', $sheets['Оборачиваемость'][1][1]);
        self::assertSame('=HYPERLINK("http://evil")', $sheets['Оборачиваемость'][1][2]);
    }

    public function testExportRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL);

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    /**
     * @return array<string, list<list<mixed>>> sheet name → rows
     */
    private function download(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);

        $path = tempnam(sys_get_temp_dir(), 'turnover_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $client->getInternalResponse()->getContent());

        $sheets = [];
        $reader = new Reader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $sheets[$sheet->getName()][] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $sheets;
    }
}
