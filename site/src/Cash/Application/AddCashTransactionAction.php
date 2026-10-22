<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanMatcher;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class AddCashTransactionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashTransactionService $transactions,
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly CashTransactionRepository $txRepo,
        private readonly PaymentPlanMatcher $paymentPlanMatcher,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
        private readonly CashTransactionSplitSynchronizer $splitSynchronizer,
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * Создание транзакции ДДС.
     * Важно: сперва пишем транзакцию (flush), затем — пересчитываем факты, чтобы расчёт шёл по актуальным данным.
     *
     * @throws ORMException
     */
    public function __invoke(CashTransactionDTO $dto): CashTransaction
    {
        $company = $this->transactions->resolveCompany($dto->companyId);
        $account = $this->transactions->resolveAccount($dto->moneyAccountId, $dto->companyId);
        $currency = $this->transactions->resolveCurrencyForAccount($dto->currency, $account);
        $counterparty = $this->transactions->resolveCounterparty($dto->counterpartyId, $dto->companyId);
        $category = $this->transactions->resolveCategory($dto->cashflowCategoryId, $dto->companyId);

        // --- Безопасность: запрет операций в закрытом периоде компании
        $this->transactions->assertNotLockedForCompany($company, $dto->occurredAt);

        // Повторная отправка формы создания: транзакция с ключом повтора уже есть — она и
        // результат. Гонку двух одновременных отправок останавливает PK (ключ = ID).
        if (null !== $dto->idempotencyKey) {
            $repeated = $this->txRepo->findOneByIdAndCompanyId($dto->idempotencyKey, $dto->companyId);
            if ($repeated instanceof CashTransaction) {
                return $repeated;
            }
        }

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
                    $existingTransaction->setCounterparty($counterparty);
                    $changed = true;
                }
            } elseif (null !== $existingCounterparty) {
                $existingTransaction->setCounterparty(null);
                $changed = true;
            }

            if ($changed) {
                $this->entityManager->flush();
            }

            return $existingTransaction;
        }

        // Создаём сущность транзакции
        $amount = $this->transactions->requireAmount($dto);
        $tx = new CashTransaction(
            $dto->idempotencyKey ?? Uuid::uuid4()->toString(),
            $company,
            $account,
            $dto->direction,
            $amount,
            $currency,
            $dto->occurredAt
        );

        // Опциональные связи
        $responsibilityPair = $this->responsibilityCenterResolver->resolveForCreate(
            $dto->companyId,
            $dto->projectDirectionId,
            $dto->responsibilityCenterId,
        );
        $projectDirection = $this->transactions->resolveProjectDirection($responsibilityPair->projectDirectionId, $dto->companyId);

        $tx
            ->setDescription($dto->description)
            ->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection)
            ->setResponsibilityCenterId($responsibilityPair->responsibilityCenterId);

        $tx->setImportSource($dto->importSource);
        $tx->setExternalId($dto->externalId);
        $tx->setDedupeHash($dto->dedupeHash);
        $tx->setRawData($dto->rawData ?? []);

        $this->transactions->applyVat($tx, $company, $dto->direction, $amount);

        $this->splitSynchronizer->sync($tx, CashTransactionSplitSource::MANUAL);

        // Сохраняем
        $this->entityManager->persist($tx);
        try {
            $this->entityManager->flush(); // ← flush перед пересчётом обязателен
        } catch (UniqueConstraintViolationException $exception) {
            // Две одновременные отправки одной формы: обе не нашли транзакцию по ключу,
            // вторая упёрлась в PK. Её результат — транзакция первой.
            return $this->concurrentlyCreated($dto, $exception);
        }

        // Совпадение с плановым платежом сохраняется до пересчёта, как раньше
        // делал сам матчер (flush вынесен сюда, этап 5.3).
        if (null !== $this->paymentPlanMatcher->matchForTransaction($tx)) {
            $this->entityManager->flush();
        }

        // После фиксации транзакции обязательно пересчитываем факты и инвалидируем snapshot:
        // виджеты dashboard читают агрегированные данные, поэтому без инвалидации пользователь
        // может увидеть устаревший слепок в пределах TTL даже после успешного сохранения.
        $from = $dto->occurredAt->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0); // правая граница — сегодня, дальше DailyBalanceRecalculator сам расширит до макс. факта
        $this->recalculator->recalcRange($company, $from, $to, [(string) $account->getId()]);
        $this->eventDispatcher->dispatch(new CashTransactionsChanged((string) $company->getId()));

        return $tx;
    }

    /**
     * Транзакция, созданная параллельной отправкой той же формы. EntityManager после ошибки
     * flush закрыт — читаем свежим. Ключ чужой компании — не повтор: исключение пробрасывается.
     */
    private function concurrentlyCreated(CashTransactionDTO $dto, UniqueConstraintViolationException $exception): CashTransaction
    {
        $key = $dto->idempotencyKey;
        if (null === $key) {
            throw $exception;
        }

        $ownerCompanyId = $this->entityManager->getConnection()->fetchOne(
            'SELECT company_id FROM cash_transaction WHERE id = :id',
            ['id' => $key],
        );
        if (false === $ownerCompanyId || (string) $ownerCompanyId !== $dto->companyId) {
            throw $exception;
        }

        $this->managerRegistry->resetManager();
        $entityManager = $this->managerRegistry->getManager();
        \assert($entityManager instanceof EntityManagerInterface);

        $transaction = $entityManager->getRepository(CashTransaction::class)->findOneBy(['id' => $key, 'company' => $dto->companyId]);
        if (!$transaction instanceof CashTransaction) {
            throw $exception;
        }

        return $transaction;
    }
}
