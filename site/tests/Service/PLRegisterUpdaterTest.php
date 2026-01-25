<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Company\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Enum\PLFlow;
use App\Repository\DocumentRepository;
use App\Repository\PLDailyTotalRepository;
use App\Service\PlNatureResolver;
use App\Service\PLRegisterUpdater;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PLRegisterUpdaterTest extends TestCase
{
    public function testAggregateDocumentsSkipsOperationsWithoutPlCategory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $dailyTotals = $this->createMock(PLDailyTotalRepository::class);
        $documentRepository = $this->createMock(DocumentRepository::class);

        $service = new PLRegisterUpdater(
            $em,
            $dailyTotals,
            $documentRepository,
            new PlNatureResolver(),
        );

        $company = $this->createCompany();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setDate(new \DateTimeImmutable('2024-01-10'));

        $incomeCategory = $this->createCategory($company, 'Income', PLFlow::INCOME);
        $expenseCategory = $this->createCategory($company, 'Expense', PLFlow::EXPENSE);
        $neutralCategory = $this->createCategory($company, 'Neutral', PLFlow::NONE);

        $incomeOperation = new DocumentOperation();
        $incomeOperation->setAmount('100');
        $incomeOperation->setCategory($incomeCategory);
        $document->addOperation($incomeOperation);

        $expenseOperation = new DocumentOperation();
        $expenseOperation->setAmount('75');
        $expenseOperation->setCategory($expenseCategory);
        $document->addOperation($expenseOperation);

        $withoutCategory = new DocumentOperation();
        $withoutCategory->setAmount('50');
        $document->addOperation($withoutCategory);

        $neutralOperation = new DocumentOperation();
        $neutralOperation->setAmount('125');
        $neutralOperation->setCategory($neutralCategory);
        $document->addOperation($neutralOperation);

        $method = new \ReflectionMethod(PLRegisterUpdater::class, 'aggregateDocuments');
        $method->setAccessible(true);

        $result = $method->invoke($service, [$document]);

        $dateKey = $document->getDate()->setTime(0, 0)->format('Y-m-d');
        self::assertArrayHasKey($dateKey, $result);

        $categories = $result[$dateKey]['categories'];
        self::assertCount(2, $categories, 'Only operations with PL category and resolved nature are aggregated.');

        $byCategoryId = [];
        foreach ($categories as $data) {
            $byCategoryId[$data['category']->getId()] = $data;
        }

        self::assertArrayHasKey($incomeCategory->getId(), $byCategoryId);
        self::assertSame(100.0, $byCategoryId[$incomeCategory->getId()]['income']);
        self::assertSame(0.0, $byCategoryId[$incomeCategory->getId()]['expense']);

        self::assertArrayHasKey($expenseCategory->getId(), $byCategoryId);
        self::assertSame(0.0, $byCategoryId[$expenseCategory->getId()]['income']);
        self::assertSame(75.0, $byCategoryId[$expenseCategory->getId()]['expense']);

        self::assertArrayNotHasKey($neutralCategory->getId(), $byCategoryId);
    }

    private function createCompany(): Company
    {
        $user = new \App\Company\Entity\User(Uuid::uuid4()->toString());
        $user->setEmail('pnl@example.com');
        $user->setPassword('secret');

        return new Company(Uuid::uuid4()->toString(), $user);
    }

    private function createCategory(Company $company, string $name, PLFlow $flow): PLCategory
    {
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $category->setFlow($flow);

        return $category;
    }
}
