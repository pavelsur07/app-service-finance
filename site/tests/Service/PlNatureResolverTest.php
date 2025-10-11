<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\PLFlow;
use App\Enum\PlNature;
use App\Service\PlNatureResolver;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PlNatureResolverTest extends TestCase
{
    public function testOperationCategoryOverridesDocumentType(): void
    {
        $resolver = new PlNatureResolver();

        $company = $this->createCompany();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setType(DocumentType::PURCHASE_INVOICE);

        $revenueRoot = $this->createCategory($company, 'Revenue');
        $revenueChild = $this->createCategory($company, 'Marketplace Sales', $revenueRoot);
        $revenueChild->setFlow(PLFlow::INCOME);

        $operation = new DocumentOperation();
        $operation->setDocument($document);
        $operation->setCategory($revenueChild);

        self::assertSame(PlNature::INCOME, $resolver->forOperation($operation));
    }

    public function testReturnsNullWhenCategoryMissing(): void
    {
        $resolver = new PlNatureResolver();

        $company = $this->createCompany();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setType(DocumentType::SALES_DELIVERY_NOTE);

        $operation = new DocumentOperation();
        $operation->setDocument($document);

        self::assertNull($resolver->forOperation($operation));
    }

    public function testDocumentReturnsMixedWhenHasIncomeAndExpenseOperations(): void
    {
        $resolver = new PlNatureResolver();

        $company = $this->createCompany();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setType(DocumentType::SALES_DELIVERY_NOTE);

        $revenueRoot = $this->createCategory($company, 'Revenue');
        $revenueChild = $this->createCategory($company, 'Marketplace Sales', $revenueRoot);
        $revenueChild->setFlow(PLFlow::INCOME);
        $expenseRoot = $this->createCategory($company, 'OPEX');
        $expenseChild = $this->createCategory($company, 'Marketing', $expenseRoot);
        $expenseChild->setFlow(PLFlow::EXPENSE);

        $incomeOperation = new DocumentOperation();
        $incomeOperation->setCategory($revenueChild);
        $document->addOperation($incomeOperation);

        $expenseOperation = new DocumentOperation();
        $expenseOperation->setCategory($expenseChild);
        $document->addOperation($expenseOperation);

        self::assertSame('MIXED', $resolver->forDocument($document));
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('test@example.com');
        $user->setPassword('secret');

        return new Company(Uuid::uuid4()->toString(), $user);
    }

    private function createCategory(Company $company, string $name, ?PLCategory $parent = null): PLCategory
    {
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        if ($parent) {
            $category->setParent($parent);
        }

        return $category;
    }
}
