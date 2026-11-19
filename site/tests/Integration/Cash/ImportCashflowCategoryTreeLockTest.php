<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash;

use App\Cash\Application\Category\ImportCashflowCategoryTreeAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Tests\Builders\Cash\CashCategorySuggestionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;

/**
 * Загрузка дерева статей ДДС ждёт блокировку дерева компании — ту же, что берёт синхронизация
 * эталона группы. Блокировку держит другая сессия PostgreSQL.
 */
final class ImportCashflowCategoryTreeLockTest extends IntegrationTestCase
{
    public function testAppliedImportReopensUndecidedAiSuggestions(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('cf-import-ai-'.$companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $rentId = Uuid::uuid7()->toString();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->withName('Счёт')->forCompany($company)->build();
        $transaction = new CashTransaction(Uuid::uuid7()->toString(), $company, $account, CashDirection::OUTFLOW, '100.00', 'RUB', new \DateTimeImmutable('2026-11-10'));
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()->withId(Uuid::uuid7()->toString())->withCompanyId($companyId)
            ->withTransactionId((string) $transaction->getId())->withTaskId(Uuid::uuid7()->toString())->suggesting($rentId)->build();
        foreach ([$owner, $company, (new CashflowCategory($rentId, $company))->setName('Аренда'), $account, $transaction, $suggestion] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $action = self::getContainer()->get(ImportCashflowCategoryTreeAction::class);
        $payload = [['id' => $rentId, 'name' => 'Аренда'], ['name' => 'НДФЛ']];
        $preview = $action->preview($company, $payload);
        $action($company, $payload, $preview->newIds, $preview->fingerprint);

        self::assertSame('stale', $this->em->getConnection()->fetchOne('SELECT status FROM cash_category_suggestion WHERE id = ?', [$suggestion->getId()]), 'ИИ переспросит с новой статьёй');
    }

    public function testImportWaitsForCompanyTreeLockHeldByAnotherSession(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('cf-import-'.$companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $rentId = Uuid::uuid7()->toString();
        foreach ([$owner, $company, (new CashflowCategory($rentId, $company))->setName('Аренда')] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $action = self::getContainer()->get(ImportCashflowCategoryTreeAction::class);
        $payload = [['id' => $rentId, 'name' => 'Аренда помещений']];
        $fingerprint = $action->preview($company, $payload)->fingerprint;

        $other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $other->beginTransaction();
        $other->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', [$companyId]);
        $this->em->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            $action($company, $payload, [], $fingerprint);
            self::fail('Загрузка должна ждать блокировку дерева компании.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
        } finally {
            $other->rollBack();
            $other->close();
        }
    }
}
