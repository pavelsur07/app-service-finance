<?php

namespace App\Cash\Service\Transaction;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanMatcher;
use App\Cash\Service\Vat\VatCalculator;
use App\Cash\Service\Vat\VatPolicy;
use App\Company\Entity\Company;
use App\DTO\CashTransactionDTO;
use App\Entity\Counterparty;
use App\Entity\ProjectDirection;
use App\Exception\CurrencyMismatchException;
use App\Message\ApplyAutoRulesForTransaction;
use App\Service\DailyBalanceRecalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

class CashTransactionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private DailyBalanceRecalculator $recalculator,   // ← как и было: после CRUD пересчитываем факты
        private CashTransactionRepository $txRepo,
        private MessageBusInterface $messageBus,
        private PaymentPlanMatcher $paymentPlanMatcher,
        private VatPolicy $vatPolicy,
        private VatCalculator $vatCalculator,
        private SnapshotCacheInvalidator $snapshotCacheInvalidator,
    ) {
    }

    /**
     * Создание транзакции ДДС.
     * Важно: сперва пишем транзакцию (flush), затем — пересчитываем факты, чтобы расчёт шёл по актуальным данным.
     *
     * @throws ORMException
     */
    public function add(CashTransactionDTO $dto): CashTransaction
    {
        /** @var Company $company */
        $company = $this->em->getReference(Company::class, $dto->companyId);

        // --- Безопасность: запрет операций в закрытом периоде компании
        $this->assertNotLockedForCompany($company, $dto->occurredAt);

        $existingTransaction = null;
        if (null !== $dto->importSource && null !== $dto->externalId) {
            $existingTransaction = $this->txRepo->findOneByImport(
                $dto->companyId,
                $dto->importSource,
                $dto->externalId,
            );
        }

        if ($existingTransaction instanceof CashTransaction) {
            $changed = false;

            if ($dto->description !== $existingTransaction->getDescription()) {
                $existingTransaction->setDescription($dto->description);
                $changed = true;
            }

            $existingCounterparty = $existingTransaction->getCounterparty();
            $existingCounterpartyId = $existingCounterparty?->getId();

            if (null !== $dto->counterpartyId) {
                if ($existingCounterpartyId !== $dto->counterpartyId) {
                    $counterparty = $this->em->getReference(Counterparty::class, $dto->counterpartyId);
                    $existingTransaction->setCounterparty($counterparty);
                    $changed = true;
                }
            } elseif (null !== $existingCounterparty) {
                $existingTransaction->setCounterparty(null);
                $changed = true;
            }

            if ($changed) {
                $this->em->flush();
            }

            return $existingTransaction;
        }

        /** @var MoneyAccount $account */
        $account = $this->em->getReference(MoneyAccount::class, $dto->moneyAccountId);

        // Контроль валюты счёта vs. валюты транзакции
        if ($dto->currency !== $account->getCurrency()) {
            throw new CurrencyMismatchException();
        }

        // Создаём сущность транзакции
        $tx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $dto->direction,
            $dto->amount,
            $dto->currency,
            $dto->occurredAt
        );

        // Опциональные связи
        $counterparty = $dto->counterpartyId
            ? $this->em->getReference(Counterparty::class, $dto->counterpartyId)
            : null;
        $category = $dto->cashflowCategoryId
            ? $this->em->getReference(CashflowCategory::class, $dto->cashflowCategoryId)
            : null;
        $projectDirection = $dto->projectDirectionId
            ? $this->em->getReference(ProjectDirection::class, $dto->projectDirectionId)
            : null;

        $tx
            ->setDescription($dto->description)
            ->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection);

        $tx->setImportSource($dto->importSource);
        $tx->setExternalId($dto->externalId);

        $this->applyVat($tx, $company, $dto->direction, $dto->amount);

        // Сохраняем
        $this->em->persist($tx);
        $this->em->flush(); // ← flush перед пересчётом обязателен

        $this->paymentPlanMatcher->matchForTransaction($tx);

        $this->messageBus->dispatch(new ApplyAutoRulesForTransaction(
            (string) $tx->getId(),
            (string) $company->getId(),
            new \DateTimeImmutable(),
        ));

        // После фиксации транзакции обязательно пересчитываем факты и инвалидируем snapshot:
        // виджеты dashboard читают агрегированные данные, поэтому без инвалидации пользователь
        // может увидеть устаревший слепок в пределах TTL даже после успешного сохранения.
        $from = $dto->occurredAt->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0); // правая граница — сегодня, дальше DailyBalanceRecalculator сам расширит до макс. факта
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        $this->snapshotCacheInvalidator->invalidateForCompany($company);

        return $tx;
    }

    /**
     * Редактирование транзакции.
     * Пересчитываем минимально возможный диапазон (с минимальной из старой/новой даты).
     */
    public function update(CashTransaction $tx, CashTransactionDTO $dto): CashTransaction
    {
        $company = $tx->getCompany();
        $oldAccount = $tx->getMoneyAccount();
        $oldDate = $tx->getOccurredAt();

        // --- Безопасность: нельзя править/переносить транзакцию в закрытый период
        $this->assertNotLockedForCompany($company, $oldDate);
        $this->assertNotLockedForCompany($company, $dto->occurredAt);

        /** @var MoneyAccount $account */
        $account = $this->em->getReference(MoneyAccount::class, $dto->moneyAccountId);

        // Контроль валюты
        if ($dto->currency !== $account->getCurrency()) {
            throw new CurrencyMismatchException();
        }

        // Обновляем поля
        $tx->setMoneyAccount($account)
            ->setDirection($dto->direction)
            ->setAmount($dto->amount)
            ->setCurrency($dto->currency)
            ->setOccurredAt($dto->occurredAt)
            ->setDescription($dto->description);

        $counterparty = $dto->counterpartyId
            ? $this->em->getReference(Counterparty::class, $dto->counterpartyId)
            : null;
        $category = $dto->cashflowCategoryId
            ? $this->em->getReference(CashflowCategory::class, $dto->cashflowCategoryId)
            : null;
        $projectDirection = $dto->projectDirectionId
            ? $this->em->getReference(ProjectDirection::class, $dto->projectDirectionId)
            : null;

        $tx->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection);

        $this->applyVat($tx, $company, $dto->direction, $dto->amount);

        // Сохраняем изменения
        $this->em->flush(); // ← flush перед пересчётом обязателен

        $this->messageBus->dispatch(new ApplyAutoRulesForTransaction(
            (string) $tx->getId(),
            (string) $company->getId(),
            new \DateTimeImmutable(),
        ));

        // Минимальный диапазон пересчёта: от min(старая дата, новая дата) до сегодня
        $from = min($dto->occurredAt, $oldDate)->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // После обновления пересчитываем факты по затронутым счетам, а затем инвалидируем snapshot,
        // чтобы следующий запрос dashboard гарантированно строился на новой версии данных.
        // Это защищает от ситуации, когда расчёт уже завершился, но API ещё отдаёт кэш старой версии.
        // Пересчёт по старому счёту (на случай переноса даты/суммы/направления)
        $this->recalculator->recalcRange($company, $from, $to, [$oldAccount->getId()]);

        // Если счёт изменили — пересчитываем и по новому счёту
        if ($oldAccount->getId() !== $account->getId()) {
            $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        }

        $this->snapshotCacheInvalidator->invalidateForCompany($company);

        return $tx;
    }

    private function applyVat(CashTransaction $tx, Company $company, CashDirection $direction, string $amount): void
    {
        $rate = $this->vatPolicy->decideForCash($company, $direction);

        if (null === $rate) {
            $tx->setVatRatePercent(null);
            $tx->setVatAmount(null);

            return;
        }

        $split = $this->vatCalculator->splitGross((float) $amount, $rate);
        $tx->setVatRatePercent($rate);
        $tx->setVatAmount($split['vat']);
    }

    /**
     * Удаление транзакции.
     * Сначала удаляем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function delete(CashTransaction $tx, ?string $userId = null, ?string $reason = null): void
    {
        $company = $tx->getCompany();

        // --- Безопасность: нельзя удалять транзакцию из закрытого периода
        $this->assertNotLockedForCompany($company, $tx->getOccurredAt());

        $account = $tx->getMoneyAccount();

        // Диапазон пересчёта
        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // Удаляем и фиксируем
        $tx->markDeleted($userId, $reason);
        $this->em->flush(); // ← flush перед пересчётом обязателен

        // Для удаления действуем так же: сначала фиксируем изменение, потом обновляем факты,
        // и только после этого повышаем версию snapshot cache. Такой порядок гарантирует,
        // что новый cache key укажет на уже актуальное состояние регистров и остатков.
        // Пересчёт по счёту
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        $this->snapshotCacheInvalidator->invalidateForCompany($company);
    }

    /**
     * Восстановление транзакции.
     * Сначала восстанавливаем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function restore(CashTransaction $tx): void
    {
        $company = $tx->getCompany();

        // --- Безопасность: нельзя восстанавливать транзакцию из закрытого периода
        $this->assertNotLockedForCompany($company, $tx->getOccurredAt());

        $account = $tx->getMoneyAccount();

        // Диапазон пересчёта
        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // Восстанавливаем и фиксируем
        $tx->restore();
        $this->em->flush(); // ← flush перед пересчётом обязателен

        // Пересчёт по счёту
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
    }

    /**
     * Жёсткая проверка «замка периода» на уровне компании.
     * Если в Company задана дата financeLockBefore, то любые операции с датой строго ранее этой даты запрещены.
     * Бросаем DomainException — контроллер покажет сообщение пользователю.
     */
    private function assertNotLockedForCompany(Company $company, \DateTimeInterface $date): void
    {
        $lock = $company->getFinanceLockBefore();
        if (!$lock) {
            return; // замка нет — ничего не запрещаем
        }

        $lock = $lock->setTime(0, 0);
        $current = $date instanceof \DateTimeImmutable
            ? $date->setTime(0, 0)
            : \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        if ($current < $lock) {
            throw new \DomainException(sprintf('Период закрыт. Операции с датами ранее %s запрещены.', $lock->format('d.m.Y')));
        }
    }
}
