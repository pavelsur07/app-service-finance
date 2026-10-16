<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * «Создать документ ОПиУ» из операции ДДС: обычный путь создаёт документ; испорченная статья
 * ДДС со статьёй ОПиУ другой компании даёт сообщение и запись в лог, а не 500 и не документ
 * с чужой статьёй (инвариант Document, DocumentForeignReferenceException).
 */
final class CashTransactionCreatePnlDocumentTest extends WebTestCaseBase
{
    public function testCreatesDocumentFromOwnReferences(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$tx] = $this->persistTransaction($company);

        $this->post($client, $user, $company, $tx);

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#^/documents/[0-9a-f-]{36}/edit$#', $location);
        self::assertSame(1, $this->documentCount($tx));
    }

    public function testForeignPlCategoryOfCashflowCategoryShowsMessageInsteadOf500(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [, $foreign] = $this->prepareCompanyContext();
        [$tx, $cashflowCategory] = $this->persistTransaction($company);

        $foreignCategory = (new PLCategory(Uuid::uuid4()->toString(), $foreign))->setName('Чужая статья')->setFlow(PLFlow::EXPENSE);
        $this->em()->persist($foreignCategory);
        $this->em()->flush();
        // Сущность ДДС такую ссылку примет (это не её инвариант), но портим в БД напрямую:
        // так данные выглядели бы после ошибки в обход приложения.
        $this->em()->getConnection()->executeStatement(
            'UPDATE cashflow_categories SET pl_category_id = :pl WHERE id = :id',
            ['pl' => $foreignCategory->getId(), 'id' => $cashflowCategory->getId()],
        );
        $this->em()->clear();

        $this->post($client, $user, $company, $tx);

        self::assertResponseRedirects('/finance/cash-transactions/'.$tx->getId());
        self::assertSame(0, $this->documentCount($tx), 'Документ с чужой статьёй создан.');
        $client->followRedirect();
        self::assertStringContainsString('указан справочник другой компании', (string) $client->getResponse()->getContent());
    }

    private function post(KernelBrowser $client, User $user, Company $company, CashTransaction $tx): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('POST', '/finance/cash-transactions/'.$tx->getId().'/create-pnl-document', [
            '_token' => $this->csrfToken($client, 'create_pnl_document'.$tx->getId()),
        ]);
    }

    private function documentCount(CashTransaction $tx): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM documents WHERE cash_transaction_id = :id',
            ['id' => $tx->getId()],
        );
    }

    /**
     * @return array{0: CashTransaction, 1: CashflowCategory}
     */
    private function persistTransaction(Company $company): array
    {
        $em = $this->em();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-07-01'));
        $plCategory = (new PLCategory(Uuid::uuid4()->toString(), $company))->setName('Выбытия')->setFlow(PLFlow::EXPENSE);
        $cashflowCategory = (new CashflowCategory(Uuid::uuid4()->toString(), $company))
            ->setName('Оплата услуг')
            ->setAllowPlDocument(true)
            ->setPlCategory($plCategory);
        $tx = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, CashDirection::OUTFLOW, '1000.00', 'RUB', new \DateTimeImmutable('2026-07-18'));
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Проект');
        $tx->setDescription('Оплата')->setCashflowCategory($cashflowCategory)->setProjectDirection($project);
        $tx->replaceSplits([new CashTransactionSplit($tx, $cashflowCategory, $tx->getAmount(), CashTransactionSplitSource::MANUAL)]);

        foreach ([$account, $plCategory, $cashflowCategory, $project, $tx] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$tx, $cashflowCategory];
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
