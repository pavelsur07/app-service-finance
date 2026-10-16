<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use Ramsey\Uuid\Uuid;

/**
 * Данные для тестов списков раздела «Документы ОПиУ»: вкладок документов и операций.
 * Класс-хозяин держит `$this->em` и компанию по умолчанию `$this->company`.
 */
trait DocumentListFixturesTrait
{
    /**
     * @param list<array{0: PLCategory|null, 1: string, 2?: ProjectDirection|null, 3?: string|null, 4?: Counterparty|null, 5?: string|null}> $operations
     */
    protected function persistDocument(
        string $number,
        ?string $description = null,
        ?Company $company = null,
        ?Counterparty $counterparty = null,
        ?ProjectDirection $project = null,
        ?string $responsibilityCenterId = null,
        array $operations = [],
        DocumentType $type = DocumentType::OTHER,
        ?string $currency = null,
        string $date = '2026-10-08',
    ): Document {
        $document = new Document(Uuid::uuid4()->toString(), $company ?? $this->company);
        $document->setType($type);
        if (null !== $currency) {
            $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($company ?? $this->company)->withCurrency($currency)->build();
            $transaction = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($company ?? $this->company)->withMoneyAccount($account)->withAmount('1000.00')->withCurrency($currency)->build();
            $this->em->persist($account);
            $this->em->persist($transaction);
            $document->setCashTransaction($transaction);
        }
        $document->setNumber($number);
        $document->setDescription($description);
        $document->setDate(new \DateTimeImmutable($date));
        $document->setCounterparty($counterparty);
        $document->setProjectDirection($project);
        $document->setResponsibilityCenterId($responsibilityCenterId);

        foreach ($operations as $row) {
            $operation = new DocumentOperation();
            $operation->setCategory($row[0]);
            $operation->setAmount($row[1]);
            $operation->setProjectDirection($row[2] ?? null);
            $operation->setResponsibilityCenterId($row[3] ?? null);
            $operation->setCounterparty($row[4] ?? null);
            $operation->setComment($row[5] ?? null);
            $document->addOperation($operation);
            $this->em->persist($operation);
        }

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    protected function persistCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex(9100 + $index)->build();
        $company = CompanyBuilder::aCompany()->withIndex(9100 + $index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    protected function persistCategory(Company $company, string $name, PLFlow $flow): PLCategory
    {
        $category = PLCategoryBuilder::aPLCategory()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName($name)
            ->withFlow($flow)
            ->build();
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    protected function persistCenter(Company $company, string $code): string
    {
        $center = new FinancialResponsibilityCenter((string) $company->getId(), $code, $code);
        $this->em->persist($center);
        $this->em->flush();

        return $center->getId();
    }

    protected function persistProject(Company $company, string $name): ProjectDirection
    {
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    protected function persistCounterparty(Company $company, string $name): Counterparty
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName($name)
            ->build();
        $this->em->persist($counterparty);
        $this->em->flush();

        return $counterparty;
    }
}
