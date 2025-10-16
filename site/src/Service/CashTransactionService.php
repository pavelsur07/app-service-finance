<?php

namespace App\Service;

use App\DTO\CashTransactionDTO;
use App\Entity\CashflowCategory;
use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\ProjectDirection;
use App\Exception\CurrencyMismatchException;
use App\Message\ApplyAutoRulesForTransaction;
use App\Repository\CashTransactionRepository;
use App\Service\PaymentPlan\PaymentPlanMatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Ramsey\Uuid\Uuid;

class CashTransactionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private DailyBalanceRecalculator $recalculator,   // ← как и было: после CRUD пересчитываем факты
        private CashTransactionRepository $txRepo,
        private MessageBusInterface $messageBus,
        private PaymentPlanMatcher $paymentPlanMatcher,
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

        if ($dto->externalId) {
            $tx->setExternalId($dto->externalId);
        }

        // Сохраняем
        $this->em->persist($tx);
        $this->em->flush(); // ← flush перед пересчётом обязателен

        $this->paymentPlanMatcher->matchForTransaction($tx);

        $this->messageBus->dispatch(new ApplyAutoRulesForTransaction(
            (string) $tx->getId(),
            (string) $company->getId(),
            new \DateTimeImmutable(),
        ));

        // Пересчитываем только затронутый счёт и даты
        $from = $dto->occurredAt->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0); // правая граница — сегодня, дальше DailyBalanceRecalculator сам расширит до макс. факта
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);

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

        // Пересчёт по старому счёту (на случай переноса даты/суммы/направления)
        $this->recalculator->recalcRange($company, $from, $to, [$oldAccount->getId()]);

        // Если счёт изменили — пересчитываем и по новому счёту
        if ($oldAccount->getId() !== $account->getId()) {
            $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        }

        return $tx;
    }

    /**
     * Удаление транзакции.
     * Сначала удаляем и фиксируем это в БД, затем запускаем пересчёт по затронутому счёту.
     */
    public function delete(CashTransaction $tx): void
    {
        $company = $tx->getCompany();

        // --- Безопасность: нельзя удалять транзакцию из закрытого периода
        $this->assertNotLockedForCompany($company, $tx->getOccurredAt());

        $account = $tx->getMoneyAccount();

        // Диапазон пересчёта
        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // Удаляем и фиксируем
        $this->em->remove($tx);
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
