<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Infrastructure\Export\CashTransactionXlsxExporter;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Reader\XLSX\Reader;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CashTransactionExportControllerTest extends WebTestCaseBase
{
    private const PERIOD_URL = '/finance/cash-transactions/export?dateFrom=2026-01-01&dateTo=2026-01-31';

    /**
     * Экран отдаёт 20 записей на страницу — экспорт обязан отдать весь период целиком.
     */
    public function testExportsAllRowsInPeriodBeyondFirstPage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('cash-export@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);

        $account = MoneyAccountBuilder::aMoneyAccount()
            ->forCompany($company)
            ->withName('Расчётный счёт')
            ->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withCompany($company)
            ->withName('Аренда')
            ->build();
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withCompany($company)
            ->withName('ООО «Ромашка»')
            ->build();
        $em->persist($account);
        $em->persist($category);
        $em->persist($counterparty);

        for ($i = 1; $i <= 25; ++$i) {
            $tx = CashTransactionBuilder::aCashTransaction()
                ->forCompany($company)
                ->withMoneyAccount($account)
                ->withCashflowCategory($category)
                ->withDirection(CashDirection::OUTFLOW)
                ->withAmount('100.50')
                ->build();
            $tx->setOccurredAt(new \DateTimeImmutable(sprintf('2026-01-%02d', $i)));
            $tx->setCounterparty($counterparty);
            $tx->setDescription(sprintf('Операция %d', $i));
            $em->persist($tx);
        }

        $outsidePeriod = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $outsidePeriod->setOccurredAt(new \DateTimeImmutable('2026-02-05'));
        $outsidePeriod->setDescription('Вне периода');
        $em->persist($outsidePeriod);

        $em->flush();

        $this->authenticate($client, $owner, $company);
        $client->request('GET', self::PERIOD_URL);

        self::assertResponseIsSuccessful();

        $headers = $client->getResponse()->headers;
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $headers->get('Content-Type'),
        );
        self::assertSame(
            'attachment; filename="cash-transactions_2026-01-01_2026-01-31.xlsx"',
            $headers->get('Content-Disposition'),
        );

        $rows = $this->readXlsxRows((string) $client->getInternalResponse()->getContent());

        self::assertSame(CashTransactionXlsxExporter::HEADERS, $rows[0]);
        self::assertCount(26, $rows, 'Ожидаем шапку и все 25 строк периода, а не одну страницу в 20 записей');

        $dataRows = \array_slice($rows, 1);
        $descriptions = array_column($dataRows, 4);
        self::assertNotContains('Вне периода', $descriptions);
        self::assertContains('Операция 1', $descriptions);
        self::assertContains('Операция 25', $descriptions);

        $firstRow = $dataRows[0];
        self::assertSame('Расчётный счёт', $firstRow[1]);
        self::assertSame(-100.5, $firstRow[2], 'Расход выгружается со знаком минус');
        self::assertSame('Аренда', $firstRow[3]);
        self::assertSame('ООО «Ромашка»', $firstRow[5]);
    }

    public function testExportExcludesOtherCompanyTransactions(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('cash-export-own@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);

        $otherOwner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('cash-export-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($otherOwner)
            ->build();
        $em->persist($otherOwner);
        $em->persist($otherCompany);

        $this->persistTransaction($em, $company, 'Своя операция');
        $this->persistTransaction($em, $otherCompany, 'Чужая операция');

        $em->flush();

        $this->authenticate($client, $owner, $company);
        $client->request('GET', self::PERIOD_URL);

        self::assertResponseIsSuccessful();

        $rows = $this->readXlsxRows((string) $client->getInternalResponse()->getContent());
        $descriptions = array_column(\array_slice($rows, 1), 4);

        self::assertSame(['Своя операция'], $descriptions);
    }

    public function testIndexPageRendersExportLinkWithCurrentFilters(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withEmail('cash-export-link@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->authenticate($client, $owner, $company);
        $crawler = $client->request('GET', '/finance/cash-transactions/?dateFrom=2026-01-01&dateTo=2026-01-31');

        self::assertResponseIsSuccessful();

        $href = $crawler->filter('[data-testid="btn-export-xls"]')->attr('href');
        self::assertNotNull($href);
        self::assertStringStartsWith('/finance/cash-transactions/export', $href);
        self::assertStringContainsString('dateFrom=2026-01-01', $href);
        self::assertStringContainsString('dateTo=2026-01-31', $href);
    }

    private function persistTransaction(EntityManagerInterface $em, Company $company, string $description): void
    {
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();
        $em->persist($account);

        $tx = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withMoneyAccount($account)
            ->build();
        $tx->setOccurredAt(new \DateTimeImmutable('2026-01-15'));
        $tx->setDescription($description);
        $em->persist($tx);
    }

    private function authenticate(KernelBrowser $client, object $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /**
     * @return list<list<mixed>> первая строка — шапка
     */
    private function readXlsxRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cash_tx_export_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        $reader = new Reader();
        $reader->open($path);

        try {
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(
                        static fn ($cell) => $cell->getValue(),
                        $row->getCells(),
                    );
                }

                break; // только первый лист
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $rows;
    }
}
