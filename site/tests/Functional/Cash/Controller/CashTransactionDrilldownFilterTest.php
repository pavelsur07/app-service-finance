<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Reader\XLSX\Reader;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Фильтры списка операций, которые ставит переход из отчёта ДДС:
 * статья с поддеревом, проекты и ЦФО списком.
 */
final class CashTransactionDrilldownFilterTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Company $company;
    private MoneyAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $em = $this->em();

        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('drilldown-list@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($this->company)->build();
        $em->persist($owner);
        $em->persist($this->company);
        $em->persist($this->account);
        $em->flush();

        $this->client->loginUser($owner);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    public function testCategoryWithChildrenIncludesWholeSubtree(): void
    {
        $parent = $this->category('Parent');
        $child = $this->category('Child', $parent);
        $grandChild = $this->category('GrandChild', $child);
        $other = $this->category('Other');
        $this->tx('TX-PARENT', $parent);
        $this->tx('TX-CHILD', $child);
        $this->tx('TX-GRANDCHILD', $grandChild);
        $this->tx('TX-OTHER', $other);
        $this->em()->flush();

        $text = $this->listText(['categoryId' => $parent->getId(), 'includeChildren' => '1']);
        self::assertStringContainsString('TX-PARENT', $text);
        self::assertStringContainsString('TX-CHILD', $text);
        self::assertStringContainsString('TX-GRANDCHILD', $text);
        self::assertStringNotContainsString('TX-OTHER', $text);

        // Без флага — прежнее поведение: только сама статья.
        $exact = $this->listText(['categoryId' => $parent->getId()]);
        self::assertStringContainsString('TX-PARENT', $exact);
        self::assertStringNotContainsString('TX-CHILD', $exact);
    }

    public function testProjectAndCenterListsFilterTransactions(): void
    {
        $category = $this->category('Cat');
        $projectA = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'A');
        $projectB = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'B');
        $this->em()->persist($projectA);
        $this->em()->persist($projectB);
        $centerXEntity = new FinancialResponsibilityCenter((string) $this->company->getId(), 'CENTER_X', 'X');
        $centerYEntity = new FinancialResponsibilityCenter((string) $this->company->getId(), 'CENTER_Y', 'Y');
        $this->em()->persist($centerXEntity);
        $this->em()->persist($centerYEntity);
        $centerX = (string) $centerXEntity->getId();
        $centerY = (string) $centerYEntity->getId();

        $this->tx('TX-A-X', $category)->setProjectDirection($projectA)->setResponsibilityCenterId($centerX);
        $this->tx('TX-B-Y', $category)->setProjectDirection($projectB)->setResponsibilityCenterId($centerY);
        $this->tx('TX-NONE', $category);
        $this->em()->flush();

        $byProject = $this->listText(['projectIds' => $projectA->getId()]);
        self::assertStringContainsString('TX-A-X', $byProject);
        self::assertStringNotContainsString('TX-B-Y', $byProject);
        self::assertStringNotContainsString('TX-NONE', $byProject);

        $byBothProjects = $this->listText(['projectIds' => $projectA->getId().','.$projectB->getId()]);
        self::assertStringContainsString('TX-A-X', $byBothProjects);
        self::assertStringContainsString('TX-B-Y', $byBothProjects);
        self::assertStringNotContainsString('TX-NONE', $byBothProjects);

        $byCenter = $this->listText(['centerIds' => $centerY]);
        self::assertStringContainsString('TX-B-Y', $byCenter);
        self::assertStringNotContainsString('TX-A-X', $byCenter);
    }

    public function testDrilldownFiltersSurvivePagination(): void
    {
        $parent = $this->category('Parent');
        $child = $this->category('Child', $parent);
        for ($i = 0; $i < 21; ++$i) {
            $this->tx('TX-PAGE-'.$i, $child);
        }
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/finance/cash-transactions/', [
            'categoryId' => $parent->getId(),
            'includeChildren' => '1',
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('.js-cash-transaction-select'));
        $nextHref = (string) $crawler->filter('.pagination a')->last()->attr('href');
        self::assertStringContainsString('includeChildren=1', $nextHref);
    }

    public function testForeignCategorySubtreeShowsNothing(): void
    {
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('drilldown-foreign@example.test')->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($otherOwner)->build();
        $otherAccount = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($otherCompany)->build();
        $foreignParent = CashflowCategoryBuilder::aCashflowCategory()->withId(Uuid::uuid4()->toString())->withCompany($otherCompany)->withName('Foreign')->build();
        $em = $this->em();
        $em->persist($otherOwner);
        $em->persist($otherCompany);
        $em->persist($otherAccount);
        $em->persist($foreignParent);
        $foreignTx = CashTransactionBuilder::aCashTransaction()
            ->forCompany($otherCompany)
            ->withMoneyAccount($otherAccount)
            ->withCashflowCategory($foreignParent)
            ->build();
        $foreignTx->setDescription('TX-FOREIGN');
        $em->persist($foreignTx);
        $this->tx('TX-OWN', $this->category('Own'));
        $em->flush();

        $text = $this->listText(['categoryId' => $foreignParent->getId(), 'includeChildren' => '1']);
        self::assertStringNotContainsString('TX-FOREIGN', $text);
        self::assertStringNotContainsString('TX-OWN', $text);
    }

    public function testExportWithSubtreeKeepsOnlySubtreeSplitRows(): void
    {
        $parent = $this->category('Parent');
        $child = $this->category('Child', $parent);
        $other = $this->category('Other');
        $this->tx('EXP-CHILD', $child);
        $this->tx('EXP-OTHER', $other);
        $split = $this->tx('EXP-SPLIT', $child);
        $split->replaceSplits([
            new CashTransactionSplit($split, $child, '600.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($split, $other, '400.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em()->flush();

        $this->client->request('GET', '/finance/cash-transactions/export', [
            'dateFrom' => '2024-01-01',
            'dateTo' => '2024-01-31',
            'categoryId' => $parent->getId(),
            'includeChildren' => '1',
        ]);
        self::assertResponseIsSuccessful();

        $rows = array_slice($this->readXlsxRows((string) $this->client->getInternalResponse()->getContent()), 1);
        $descriptions = array_map(static fn (array $row): string => (string) $row[4], $rows);
        sort($descriptions);
        self::assertSame(['EXP-CHILD', 'EXP-SPLIT'], $descriptions);
    }

    public function testInvalidProjectIdRedirectsWithFlash(): void
    {
        $this->client->request('GET', '/finance/cash-transactions/', ['projectIds' => 'not-a-uuid']);

        self::assertResponseRedirects('/finance/cash-transactions/');
    }

    /** @param array<string, string|null> $query */
    private function listText(array $query): string
    {
        $crawler = $this->client->request('GET', '/finance/cash-transactions/', $query);
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('table tbody');

        return $body->count() > 0 ? $body->text() : '';
    }

    /**
     * @return list<list<mixed>> первая строка — шапка
     */
    private function readXlsxRows(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cash_tx_drilldown_export_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $reader = new Reader();
        $reader->open($path);

        try {
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_values(array_map(static fn ($cell) => $cell->getValue(), $row->getCells()));
                }

                break;
            }
        } finally {
            $reader->close();
            unlink($path);
        }

        return $rows;
    }

    private function category(string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($this->company)
            ->withName($name)
            ->build();
        $category->setParent($parent);
        $this->em()->persist($category);

        return $category;
    }

    private function tx(string $description, CashflowCategory $category): CashTransaction
    {
        $tx = CashTransactionBuilder::aCashTransaction()
            ->forCompany($this->company)
            ->withMoneyAccount($this->account)
            ->withCashflowCategory($category)
            ->build();
        $tx->setDescription($description);
        $this->em()->persist($tx);

        return $tx;
    }
}
