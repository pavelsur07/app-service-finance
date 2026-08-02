<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Transaction\CashTransactionToDocumentService;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashTransactionToDocumentServiceTest extends IntegrationTestCase
{
    public function testCopiesResponsibilityCenterToDocumentAndOperation(): void
    {
        $company = $this->createCompany();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-07-01'));

        $plCategory = (new PLCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Выбытия')
            ->setFlow(PLFlow::EXPENSE);
        $cashflowCategory = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $cashflowCategory
            ->setName('Перевод на карту')
            ->setAllowPlDocument(true)
            ->setPlCategory($plCategory);

        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_KRD', 'Краснодар');
        $tx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1000.00',
            'RUB',
            new \DateTimeImmutable('2026-07-18'),
        );
        $tx
            ->setDescription('Cash to document')
            ->setCashflowCategory($cashflowCategory)
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($center->getId());

        // Зеркальная строка разбивки — то же, что делает синхронизатор на каждом пути
        // записи; сервис читает категорию из неё, а не из колонки.
        $tx->replaceSplits([
            new CashTransactionSplit($tx, $cashflowCategory, $tx->getAmount(), CashTransactionSplitSource::MANUAL),
        ]);

        foreach ([$account, $plCategory, $cashflowCategory, $project, $center, $tx] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center));
        $this->em->flush();

        $document = self::getContainer()
            ->get(CashTransactionToDocumentService::class)
            ->createPnlDocumentFromTransaction($tx);

        self::assertSame($project->getId(), $document->getProjectDirection()?->getId());
        self::assertSame($center->getId(), $document->getResponsibilityCenterId());

        $operation = $document->getOperations()->first();
        self::assertNotFalse($operation);
        self::assertSame($project->getId(), $operation->getProjectDirection()?->getId());
        self::assertSame($center->getId(), $operation->getResponsibilityCenterId());
    }

    private function createCompany(): Company
    {
        $owner = UserBuilder::aUser()
            ->withEmail(Uuid::uuid4()->toString().'@example.test')
            ->withPasswordHash('hash')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->withName('Stage 7.7.2')
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}
