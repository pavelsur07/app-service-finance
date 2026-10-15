<?php

declare(strict_types=1);

namespace App\Cash\Service\Transaction;

use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\CurrencyMismatchException;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Cash\Service\Vat\VatCalculator;
use App\Cash\Service\Vat\VatPolicy;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\ProjectDirection;
use App\Company\Facade\CompanyFacade;
use App\Shared\Domain\ValueObject\Money;
use Ramsey\Uuid\Uuid;

/**
 * Общие проверки и разрешение ссылок для записи транзакций ДДС. Без flush():
 * сами операции — AddCashTransactionAction, UpdateCashTransactionAction,
 * DeleteCashTransactionAction, RestoreCashTransactionAction.
 */
final class CashTransactionService
{
    public function __construct(
        private readonly VatPolicy $vatPolicy,
        private readonly VatCalculator $vatCalculator,
        private readonly CompanyFacade $companyFacade,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly CashflowCategoryRepository $cashflowCategoryRepository,
        private readonly CashTransferRepository $cashTransferRepository,
    ) {
    }

    /**
     * Форма отсекает пустую сумму валидацией; эта проверка — для вызовов в обход формы
     * (фасад, импорт). В веб-форме DomainException доходит до пользователя флешем.
     */
    public function requireAmount(CashTransactionDTO $dto): string
    {
        if (null === $dto->amount || '' === $dto->amount) {
            throw new \DomainException('Укажите сумму.');
        }

        return $dto->amount;
    }

    public function applyVat(CashTransaction $tx, Company $company, CashDirection $direction, string $amount): void
    {
        $rate = $this->vatPolicy->decideForCash($company, $direction);

        if (null === $rate) {
            $tx->setVatRatePercent(null);
            $tx->setVatAmount(null);

            return;
        }

        $split = $this->vatCalculator->splitGross(Money::fromString($amount, $tx->getCurrency()), $rate);
        $tx->setVatRatePercent($rate);
        $tx->setVatAmount($split['vat']->toDecimalString());
    }

    public function resolveCompany(string $companyId): Company
    {
        if (!Uuid::isValid($companyId)) {
            throw new \DomainException('Компания не найдена.');
        }

        return $this->companyFacade->findById($companyId)
            ?? throw new \DomainException('Компания не найдена.');
    }

    public function resolveAccount(string $accountId, string $companyId): MoneyAccount
    {
        if (!Uuid::isValid($accountId)) {
            throw new \DomainException('Счёт не найден.');
        }

        return $this->moneyAccountRepository->findOneByIdAndCompanyId($accountId, $companyId)
            ?? throw new \DomainException('Счёт не найден.');
    }

    public function resolveCounterparty(?string $counterpartyId, string $companyId): ?Counterparty
    {
        if (null === $counterpartyId) {
            return null;
        }

        return $this->companyFacade->findCounterpartyByIdAndCompany($counterpartyId, $companyId)
            ?? throw new \DomainException('Контрагент не найден.');
    }

    public function resolveCategory(?string $categoryId, string $companyId): ?CashflowCategory
    {
        if (null === $categoryId) {
            return null;
        }

        if (!Uuid::isValid($categoryId)) {
            throw new \DomainException('Категория ДДС не найдена.');
        }

        return $this->cashflowCategoryRepository->findOneByIdAndCompanyId($categoryId, $companyId)
            ?? throw new \DomainException('Категория ДДС не найдена.');
    }

    public function resolveProjectDirection(string $projectDirectionId, string $companyId): ProjectDirection
    {
        return $this->companyFacade->findProjectDirectionByIdAndCompany($projectDirectionId, $companyId)
            ?? throw new \DomainException('Проект не найден.');
    }

    public function resolveCurrencyForAccount(string $currency, MoneyAccount $account): string
    {
        $normalized = FiatCurrency::fromCode($currency)->value;
        if ($normalized !== $account->getCurrency()) {
            throw new CurrencyMismatchException('Валюта транзакции должна совпадать с валютой счёта.');
        }

        return $account->getCurrency();
    }

    public function assertStandaloneTransaction(CashTransaction $transaction): void
    {
        if (null !== $this->cashTransferRepository->findOneByTransactionAndCompanyId(
            $transaction,
            (string) $transaction->getCompany()->getId(),
        )) {
            throw new \DomainException('Операцию перевода нельзя изменить отдельно. Откройте связанный перевод.');
        }
    }

    /**
     * Жёсткая проверка «замка периода» на уровне компании.
     * Если в Company задана дата financeLockBefore, то любые операции с датой строго ранее этой даты запрещены.
     * Бросаем DomainException — контроллер покажет сообщение пользователю.
     */
    public function assertNotLockedForCompany(Company $company, \DateTimeInterface $date): void
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
            throw new FinancePeriodLockedException(sprintf('Период закрыт. Операции с датами ранее %s запрещены.', $lock->format('d.m.Y')));
        }
    }
}
