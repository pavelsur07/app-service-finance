<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Вкладка «Операции» раздела «Документы ОПиУ»: строки операций, итог по всей выборке,
 * вкладки и фильтры в ссылках, пустые состояния, число запросов, изоляция компании.
 */
final class DocumentOperationIndexViewTest extends WebTestCaseBase
{
    public function testRowsAreOperationsAndTotalsCoverWholeSelection(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income, $expense] = $this->persistCategories($company);
        for ($i = 0; $i < 11; ++$i) {
            $this->persistDocument($company, sprintf('D-%02d', $i), [[$income, '1000.00'], [$expense, '250.50']]);
        }

        $crawler = $this->requestOperations($client, $user, $company, []);

        self::assertCount(20, $crawler->filter('tr[data-operation-row]'));
        $strip = self::normalize($crawler->filter('.vf-docs__totals')->text());
        self::assertStringContainsString('Найдено 22 операции · 11 документов', $strip);
        self::assertStringContainsString('Доходы 11 000,00', $strip);
        self::assertStringContainsString('Расходы 2 755,50', $strip);
        self::assertStringContainsString('Результат 8 244,50', $strip);
        self::assertStringContainsString('В документе 2 операции', self::normalize($crawler->filter('tr[data-operation-row]')->first()->text()));

        $tabs = $crawler->filter('.card-header .nav-link');
        self::assertSame('Операции 22', self::normalize($tabs->eq(1)->text()));
        self::assertSame('page', $tabs->eq(1)->attr('aria-current'));
    }

    public function testUncategorizedAndMarketplaceRows(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income, $expense] = $this->persistCategories($company);
        $this->persistDocument($company, 'MP', [[$expense, '-100.00'], [$expense, '20.00'], [null, '5.00']], DocumentType::MARKETPLACE_PL);

        $crawler = $this->requestOperations($client, $user, $company, []);

        $rows = $crawler->filter('tr[data-operation-row]')->each(static fn (Crawler $row): string => self::normalize($row->text()));
        self::assertCount(3, $rows);
        $joined = implode("\n", $rows);
        // Списание −100 — расход 100, сторно +20 — расход −20: строки складываются в итог 80.
        self::assertStringContainsString('100,00', $joined);
        self::assertStringContainsString("\u{2212}20,00", $joined);
        self::assertStringContainsString('Нужно указать статью', $joined);
        self::assertStringContainsString('не в итоге', $joined);
        self::assertStringContainsString('Расходы 80,00', self::normalize($crawler->filter('.vf-docs__totals')->text()));
    }

    public function testTabsAndChipsKeepFilters(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income] = $this->persistCategories($company);
        $this->persistDocument($company, 'IN', [[$income, '150.00']]);

        $crawler = $this->requestOperations($client, $user, $company, [
            'direction' => 'income',
            'category' => (string) $income->getId(),
            'amountFrom' => '100',
            'page' => '1',
            'projectDirection' => 'not-a-uuid',
        ]);

        self::assertCount(1, $crawler->filter('tr[data-operation-row]'));
        $documentsTab = $crawler->filter('.card-header .nav-link')->eq(0)->attr('href');
        self::assertNotNull($documentsTab);
        parse_str((string) parse_url($documentsTab, \PHP_URL_QUERY), $query);
        self::assertSame(['direction' => 'income', 'category' => (string) $income->getId(), 'amountFrom' => '100.00'], $query);
        self::assertSame('/documents/deleted', $crawler->filter('.card-header .nav-link')->eq(2)->attr('href'));

        $chips = $crawler->filter('.vf-chip')->each(static fn (Crawler $chip): string => self::normalize($chip->text()));
        self::assertContains('Статья: '.$income->getName(), $chips);
        self::assertContains('Сумма: от 100,00', $chips);
        $amountChip = $crawler->filter('.vf-chip')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), 'Сумма'));
        self::assertSame(
            '/documents/operations?direction=income&category='.$income->getId(),
            $amountChip->filter('a')->attr('href'),
        );

        // Те же фильтры на вкладке документов: документ с доходной операцией от 100 по статье.
        $documents = $client->request('GET', $documentsTab);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $documents->filter('tbody[data-document-row]'));
    }

    public function testNeighbourRoutesStillResolve(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income] = $this->persistCategories($company);
        $document = $this->persistDocument($company, 'SHOW-ME', [[$income, '1.00']]);

        $crawler = $this->requestOperations($client, $user, $company, []);
        self::assertSame('Операции · Документы ОПиУ', trim($crawler->filter('title')->text()));

        $client->request('GET', '/documents/'.$document->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SHOW-ME', (string) $client->getResponse()->getContent());

        $client->request('GET', '/documents/operations/export.json');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testEmptyStates(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $crawler = $this->requestOperations($client, $user, $company, []);
        self::assertSame('Операций ОПиУ пока нет', trim($crawler->filter('.vf-empty__title')->text()));
        self::assertCount(0, $crawler->filter('.vf-docs__totals'));

        $crawler = $this->requestOperations($client, $user, $company, ['q' => 'нет-такого']);
        self::assertSame('Операции не найдены', trim($crawler->filter('.vf-empty__title')->text()));
        self::assertCount(1, $crawler->filter('.vf-empty a[href="/documents/operations"]'));
    }

    public function testForeignCompanyOperationsAreNotShown(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [, $foreign] = $this->prepareCompanyContext();
        [$foreignIncome] = $this->persistCategories($foreign);
        $this->persistDocument($foreign, 'FOREIGN', [[$foreignIncome, '999.00']]);

        $crawler = $this->requestOperations($client, $user, $company, []);

        self::assertCount(0, $crawler->filter('tr[data-operation-row]'));
        self::assertStringNotContainsString('FOREIGN', $crawler->filter('.card')->text());
    }

    public function testQueryCountDoesNotGrowWithNumberOfRows(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income, $expense] = $this->persistCategories($company);

        $this->persistRichDocument($company, $income, $expense);
        $this->countQueries($client, $user, $company);
        $one = $this->countQueries($client, $user, $company);

        for ($i = 0; $i < 9; ++$i) {
            $this->persistRichDocument($company, $income, $expense);
        }
        $ten = $this->countQueries($client, $user, $company);

        self::assertSame($one, $ten, sprintf('Число запросов выросло с %d до %d при росте списка с 3 до 30 операций.', $one, $ten));
    }

    private function countQueries(KernelBrowser $client, User $user, Company $company): int
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->enableProfiler();
        $client->request('GET', '/documents/operations');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    /**
     * Документ со всем, что строка читает: проект, ЦФО и контрагент — у документа и у операции.
     */
    private function persistRichDocument(Company $company, PLCategory $income, PLCategory $expense): void
    {
        $em = $this->em();
        // Запрос страницы перезапускает ядро, и сущности теста уже не в EntityManager.
        $company = $em->find(Company::class, $company->getId()) ?? throw new \LogicException('Компания не найдена.');
        $income = $em->find(PLCategory::class, $income->getId()) ?? throw new \LogicException('Статья не найдена.');
        $expense = $em->find(PLCategory::class, $expense->getId()) ?? throw new \LogicException('Статья не найдена.');
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Проект '.Uuid::uuid4()->toString());
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_'.random_int(1000, 999999), 'ЦФО');
        $counterparty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('Контрагент')->build();
        $em->persist($project);
        $em->persist($center);
        $em->persist($counterparty);

        $document = $this->persistDocument($company, 'RICH', [[$income, '10.00'], [$expense, '3.00'], [null, '1.00']]);
        $document->setProjectDirection($project);
        $document->setCounterparty($counterparty);
        $document->setResponsibilityCenterId($center->getId());
        $operation = $document->getOperations()->first();
        self::assertInstanceOf(DocumentOperation::class, $operation);
        $operation->setProjectDirection($project);
        $operation->setCounterparty($counterparty);
        $operation->setResponsibilityCenterId($center->getId());
        $em->flush();
    }

    /**
     * @param array<string, string> $query
     */
    private function requestOperations(KernelBrowser $client, User $user, Company $company, array $query): Crawler
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/documents/operations', $query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    /**
     * @return array{0: PLCategory, 1: PLCategory}
     */
    private function persistCategories(Company $company): array
    {
        $em = $this->em();
        $result = [];
        foreach ([['Выручка', PLFlow::INCOME], ['Аренда', PLFlow::EXPENSE]] as [$name, $flow]) {
            $category = PLCategoryBuilder::aPLCategory()
                ->withId(Uuid::uuid4()->toString())
                ->forCompany($company)
                ->withName($name.' '.Uuid::uuid4()->toString())
                ->withFlow($flow)
                ->build();
            $em->persist($category);
            $result[] = $category;
        }
        $em->flush();

        return [$result[0], $result[1]];
    }

    /**
     * @param list<array{0: PLCategory|null, 1: string}> $operations
     */
    private function persistDocument(Company $company, string $number, array $operations, DocumentType $type = DocumentType::OTHER): Document
    {
        $em = $this->em();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setNumber($number);
        $document->setType($type);
        $document->setDate(new \DateTimeImmutable('2026-10-08'));
        foreach ($operations as [$category, $amount]) {
            $operation = new DocumentOperation();
            $operation->setCategory($category);
            $operation->setAmount($amount);
            $document->addOperation($operation);
            $em->persist($operation);
        }
        $em->persist($document);
        $em->flush();

        return $document;
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function prepareCompanyContext(): array
    {
        $em = $this->em();
        $user = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }
}
