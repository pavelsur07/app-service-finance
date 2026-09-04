<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Запрос списка ОПиУ: он тянет операции документов fetch-join'ом, и оба свойства этого
 * решения проверяются здесь. Первое — ради чего это сделано: шаблон считает доход и расход
 * по строкам каждого документа, и без fetch-join каждая строка списка добавляла свои
 * запросы. Второе — чем за это платят: fetch-join коллекции размножает строки результата,
 * поэтому постранично список обязан остаться прежним.
 */
final class DocumentIndexListQueryTest extends WebTestCaseBase
{
    private const OPERATIONS_PER_DOCUMENT = 3;

    public function testQueryCountDoesNotGrowWithNumberOfDocuments(): void
    {
        $client = static::createClient();
        [$userId, $companyId] = $this->prepareCompanyContext();

        $this->persistDocumentsWithOperations($companyId, 1);
        // Первый запрос сессии несёт разовую работу (прогрев, метаданные) и как замер непригоден.
        $this->countQueriesOnIndex($client, $userId, $companyId);
        $queriesForOneDocument = $this->countQueriesOnIndex($client, $userId, $companyId);

        $this->persistDocumentsWithOperations($companyId, 9);
        $queriesForTenDocuments = $this->countQueriesOnIndex($client, $userId, $companyId);

        self::assertSame(
            $queriesForOneDocument,
            $queriesForTenDocuments,
            \sprintf(
                'Число запросов выросло с %d до %d при росте списка с 1 до 10 документов — список грузит операции лениво.',
                $queriesForOneDocument,
                $queriesForTenDocuments,
            ),
        );
    }

    public function testPaginationCountsDocumentsAndNotTheirOperations(): void
    {
        $client = static::createClient();
        [$userId, $companyId] = $this->prepareCompanyContext();

        $this->persistDocumentsWithOperations($companyId, 25);

        $user = $this->em()->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        $firstPage = $client->request('GET', '/documents/', ['limit' => 20]);
        self::assertResponseIsSuccessful();
        self::assertCount(20, $firstPage->filter('table tbody tr'));

        $secondPage = $client->request('GET', '/documents/', ['limit' => 20, 'page' => 2]);
        self::assertResponseIsSuccessful();
        self::assertCount(5, $secondPage->filter('table tbody tr'));

        // Считать строки мало: страницы обязаны не пересекаться и вместе покрывать весь список.
        $firstPageNumbers = self::documentNumbers($firstPage);
        $secondPageNumbers = self::documentNumbers($secondPage);

        self::assertSame([], array_intersect($firstPageNumbers, $secondPageNumbers));
        self::assertCount(25, array_unique(array_merge($firstPageNumbers, $secondPageNumbers)));
    }

    /**
     * @return list<string>
     */
    private static function documentNumbers(Crawler $page): array
    {
        return $page->filter('table tbody tr td:nth-child(3)')->each(
            static fn (Crawler $cell): string => trim($cell->text()),
        );
    }

    private function countQueriesOnIndex(KernelBrowser $client, string $userId, string $companyId): int
    {
        $user = $this->em()->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->enableProfiler();
        $client->request('GET', '/documents/');

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'Профайлер выключен — измерить число запросов нечем.');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function persistDocumentsWithOperations(string $companyId, int $count): void
    {
        $em = $this->em();
        $company = $em->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);

        $income = PLCategoryBuilder::aPLCategory()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Выручка '.Uuid::uuid4()->toString())
            ->withFlow(PLFlow::INCOME)
            ->build();
        $expense = PLCategoryBuilder::aPLCategory()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('Расходы '.Uuid::uuid4()->toString())
            ->withFlow(PLFlow::EXPENSE)
            ->build();

        $em->persist($income);
        $em->persist($expense);

        for ($i = 0; $i < $count; ++$i) {
            $document = new Document(Uuid::uuid4()->toString(), $company);
            $document->setNumber('DOC-'.Uuid::uuid4()->toString());
            $document->setDate(new \DateTimeImmutable('2024-05-15'));
            $em->persist($document);

            for ($j = 0; $j < self::OPERATIONS_PER_DOCUMENT; ++$j) {
                $operation = new DocumentOperation();
                $operation->setDocument($document);
                $operation->setCategory(0 === $j % 2 ? $income : $expense);
                $operation->setAmount('100.00');

                $document->getOperations()->add($operation);
                $em->persist($operation);
            }
        }

        $em->flush();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prepareCompanyContext(): array
    {
        $this->resetDb();
        $em = $this->em();

        $user = UserBuilder::aUser()
            ->withIndex(random_int(1000, 9999))
            ->asCompanyOwner()
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withIndex(random_int(1000, 9999))
            ->withOwner($user)
            ->build();

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [(string) $user->getId(), (string) $company->getId()];
    }
}
