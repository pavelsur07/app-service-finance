<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\CashTransactionSplitInput;
use App\Cash\Application\DTO\CashTransactionSplitsInput;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сохраняет ручную разбивку транзакции ДДС по статьям.
 */
final class SaveCashTransactionSplitsAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashflowCategoryRepository $categoryRepository,
        private readonly CashflowSystemCategoryService $systemCategoryService,
        private readonly PaymentPlanMatchRepository $paymentPlanMatchRepository,
        private readonly AuditContextProvider $auditContextProvider,
    ) {
    }

    public function __invoke(CashTransaction $transaction, CashTransactionSplitsInput $input): void
    {
        $this->assertTransactionEditable($transaction);

        $before = $transaction->splitsAuditSnapshot();

        $splits = array_map(
            fn (CashTransactionSplitInput $row): CashTransactionSplit => new CashTransactionSplit(
                $transaction,
                $this->requireCategory($row->cashflowCategoryId, $transaction),
                $row->normalizedAmount(),
                CashTransactionSplitSource::MANUAL,
            ),
            $input->rows,
        );

        // Инварианты набора проверяет агрегат: равенство суммы, уникальность категорий
        // и запрет мультиразбивки по категориям с документами ОПиУ (решение D1).
        $transaction->composeSplitsManually($splits);

        $this->projectLegacyColumn($transaction);
        $this->dropStalePaymentPlanMatch($transaction);

        $after = $transaction->splitsAuditSnapshot();
        if ($before !== $after) {
            $this->entityManager->persist(new AuditLog(
                (string) $transaction->getCompany()->getId(),
                CashTransaction::class,
                (string) $transaction->getId(),
                AuditLogAction::UPDATE,
                ['splits' => [$before, $after]],
                $this->resolveActorUserId(),
            ));
        }

        $this->entityManager->flush();
    }

    /**
     * Те же ограничения, что и у обычного редактирования операции: удалённую и попавшую
     * в закрытый период менять нельзя. Форма — не единственный вход, POST можно отправить
     * напрямую, поэтому проверка стоит в Action, а не в контроллере.
     */
    private function assertTransactionEditable(CashTransaction $transaction): void
    {
        if ($transaction->isDeleted()) {
            throw new \DomainException('Удалённую операцию нельзя разбить по статьям.');
        }

        $lock = $transaction->getCompany()->getFinanceLockBefore();
        if (null !== $lock && $transaction->getOccurredAt()->setTime(0, 0) < $lock->setTime(0, 0)) {
            throw new FinancePeriodLockedException();
        }
    }

    /**
     * Колонка остаётся источником правды для ещё не переписанных мест и для отката,
     * поэтому при разбивке она проецируется в системную «Не распределено», а не в NULL:
     * так суммы отчёта по колонке остаются верными, и точкой невозврата становится
     * только удаление колонки, а не включение этой формы.
     */
    private function projectLegacyColumn(CashTransaction $transaction): void
    {
        if (1 === $transaction->getSplits()->count()) {
            $transaction->setCashflowCategory($transaction->getSingleSplitCategory());

            return;
        }

        $transaction->setCashflowCategory(
            $this->systemCategoryService->getOrCreateUnallocated($transaction->getCompany()),
        );
    }

    /**
     * Сопоставление с плановым платежом строится на одной категории (решение D2).
     *
     * Матч остаётся только если состав по-прежнему однозначен и совпадает с категорией
     * плана. Мультиразбивка ломает саму основу сопоставления, а смена единственной
     * категории делает матч ложным: календарь продолжал бы считать план закрытым
     * транзакцией, которая ему больше не соответствует.
     */
    private function dropStalePaymentPlanMatch(CashTransaction $transaction): void
    {
        $match = $this->paymentPlanMatchRepository->findOneByTransaction($transaction);
        if (null === $match) {
            return;
        }

        $category = $transaction->getSingleSplitCategory();
        if (null !== $category && $match->getPlan()->getCashflowCategory()?->getId() === $category->getId()) {
            return;
        }

        $this->entityManager->persist(new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            ['paymentPlanMatch' => ['before' => (string) $match->getPlan()->getId(), 'after' => null]],
            $this->resolveActorUserId(),
        ));

        $this->entityManager->remove($match);
    }

    private function requireCategory(?string $categoryId, CashTransaction $transaction): CashflowCategory
    {
        if (null === $categoryId) {
            throw new \DomainException('Статья ДДС не выбрана.');
        }

        $category = $this->categoryRepository->findOneByIdAndCompanyId(
            $categoryId,
            (string) $transaction->getCompany()->getId(),
        );

        if (null === $category) {
            throw new \DomainException('Статья ДДС не найдена в этой компании.');
        }

        // disabled в разметке — подсказка браузеру, а не защита: подделанный POST дошёл бы
        // до сохранения. Сумма на узле дерева задвоилась бы при свёртке в родителя.
        if (!$category->getChildren()->isEmpty()) {
            throw new \DomainException(sprintf('Статья «%s» содержит подстатьи, выберите конечную.', $category->getName()));
        }

        return $category;
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
