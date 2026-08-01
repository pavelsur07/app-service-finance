<?php

declare(strict_types=1);

namespace App\Cash\Service\Transaction;

use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\CashTransactionAutoRuleProvenanceResolver;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Держит строки разбивки в согласии с категорией транзакции на переходный период
 * dual-write: пока читатели живут на cash_transaction.cashflow_category_id, строки
 * обязаны повторять её один в один — включая случай «категория не задана» (тогда строк нет).
 *
 * Вызывается из ручного создания и редактирования (CashTransactionService), воркера
 * автоправил и ручного применения правила в контроллере. Импорты синхронизатор
 * намеренно не вызывают: они создают транзакцию без категории, а зеркалом пустой
 * колонки является отсутствие строк. Ручную мультиразбивку (её ведёт форма через
 * CashTransaction::replaceSplits) не трогает.
 */
final class CashTransactionSplitSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContextProvider $auditContextProvider,
        private readonly AutoRuleDispatchGuard $autoRuleDispatchGuard,
        private readonly CashTransactionAutoRuleProvenanceResolver $provenanceResolver,
    ) {
    }

    public function sync(CashTransaction $transaction, CashTransactionSplitSource $source): void
    {
        // Ручная мультиразбивка — источник правды сама по себе, колонка её только проецирует.
        // В Stage 1 состояние недостижимо (форма разбивки появится в Stage 4); тогда же
        // придётся запретить менять категорию и сумму такой транзакции до мутации,
        // иначе колонка и строки разойдутся именно здесь.
        if ($transaction->getSplits()->count() > 1) {
            return;
        }

        // Защита ручной категоризации от автоправил живёт на уровне колонки:
        // режим SAFE заполняет только пустые поля, а REPLACE_AUTO_ASSIGNED перезаписывает
        // только доказанно авто-назначенные. Дублировать её здесь нельзя — ранний выход
        // оставил бы колонку и строки рассинхронизированными.
        $isNew = !$this->entityManager->contains($transaction);
        $before = $transaction->splitsAuditSnapshot();
        $category = $transaction->getCashflowCategory();

        if (null === $category) {
            $transaction->clearSplits();
        } else {
            $transaction->replaceSplits([
                new CashTransactionSplit(
                    $transaction,
                    $category,
                    $transaction->getAmount(),
                    $this->resolveSource($transaction, $source, $isNew, [] === $before),
                ),
            ]);
        }

        $after = $transaction->splitsAuditSnapshot();

        if ($isNew || $before === $after) {
            // У новой транзакции состав покрыт её CREATE-записью, а неизменившийся
            // состав писать в историю незачем. Проверять на пустой $before нельзя:
            // существующая транзакция без категории — например пришедшая импортом —
            // получает первую строку именно как UPDATE, и это изменение обязано попасть
            // в историю вместе с source, которого нет в диффе скалярной колонки.
            return;
        }

        // Автоправила пишут собственный AuditLog через applicationPlan->auditDiff(),
        // второй записи о том же изменении быть не должно.
        if (null !== $this->autoRuleDispatchGuard->getApplicationPlan()) {
            return;
        }

        $this->entityManager->persist(new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            ['splits' => [$before, $after]],
            $this->resolveActorUserId(),
        ));
    }

    /**
     * Источник строки описывает происхождение категоризации, а не текущую операцию.
     *
     * Проблема возникает в окне между деплоем и завершением backfill: у существующей
     * транзакции категория уже есть, а строки ещё нет. Любая операция над другим полем
     * создаст первую строку, и без этой проверки она унаследует source текущей операции:
     * правка суммы пометила бы авто-категорию ручной, а правило, изменившее только ЦФО, —
     * ручную категорию авто-назначенной. Backfill такую транзакцию уже не выберет.
     * Поэтому если категорию эта операция не меняла, происхождение восстанавливается
     * тем же резолвером, которым его определяет backfill, в обе стороны.
     */
    private function resolveSource(
        CashTransaction $transaction,
        CashTransactionSplitSource $source,
        bool $isNew,
        bool $hadNoSplits,
    ): CashTransactionSplitSource {
        if ($isNew || !$hadNoSplits || $this->categoryChangedInThisOperation($transaction)) {
            return $source;
        }

        // Категорию эта операция не трогала, а строки ещё нет: source описывает не текущее
        // действие, а происхождение категоризации. Правило, изменившее только ЦФО, не должно
        // помечать ручную категорию как auto — и наоборот.
        return $this->provenanceResolver->resolve($transaction)->isAutoAssigned('cashflowCategory')
            ? CashTransactionSplitSource::AUTO
            : CashTransactionSplitSource::MANUAL;
    }

    private function categoryChangedInThisOperation(CashTransaction $transaction): bool
    {
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($transaction);
        if (!array_key_exists('cashflowCategory', $original)) {
            return true;
        }

        $originalCategory = $original['cashflowCategory'];

        return ($originalCategory instanceof CashflowCategory ? (string) $originalCategory->getId() : null)
            !== (null !== $transaction->getCashflowCategory()?->getId() ? (string) $transaction->getCashflowCategory()->getId() : null);
    }

    private function resolveActorUserId(): ?string
    {
        try {
            return $this->auditContextProvider->getActorUserId();
        } catch (\Throwable) {
            return null;
        }
    }
}
