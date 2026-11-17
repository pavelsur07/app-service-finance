<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Exception\CashflowGroupChartBindingException;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Ручная привязка статьи ДДС к коду эталона группы с экрана «Эталон группы». Владеет транзакцией;
 * отказ возвращается из неё значением (до записи), исключение — после: иначе EntityManager закрылся бы.
 */
final class BindCashflowCategoryGroupCodeAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncCashflowGroupChartAction $sync,
    ) {
    }

    /**
     * @throws CashflowGroupChartBindingException
     */
    public function __invoke(string $companyId, string $categoryId, string $code): void
    {
        Assert::uuid($companyId);
        Assert::uuid($categoryId);

        /** @var string|null $refusal */
        $refusal = $this->entityManager->wrapInTransaction(fn (): ?string => $this->sync->bind($companyId, $categoryId, $code));
        if (null !== $refusal) {
            throw new CashflowGroupChartBindingException($refusal);
        }
    }
}
