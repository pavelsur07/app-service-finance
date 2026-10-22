<?php

declare(strict_types=1);

namespace App\Cash\DTO;

use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Enum\CounterpartyType;
use Symfony\Component\Validator\Constraints as Assert;

class CashTransactionDTO
{
    #[Assert\NotNull]
    public string $companyId;

    #[Assert\NotNull]
    public string $moneyAccountId;

    public ?string $counterpartyId = null;
    public ?string $cashflowCategoryId = null;
    public ?string $projectDirectionId = null;
    public ?string $responsibilityCenterId = null;

    #[Assert\Choice(callback: [CashDirection::class, 'cases'])]
    public CashDirection $direction;

    /**
     * Nullable, чтобы форма могла записать пустую сумму, а валидация — её отклонить:
     * Assert\Positive пропускает null, без NotBlank пустая сумма доходила до сервиса.
     */
    #[Assert\NotBlank(message: 'Укажите сумму.')]
    #[Assert\Positive]
    public ?string $amount = null;

    #[Assert\Choice(callback: [FiatCurrency::class, 'values'])]
    public string $currency;

    #[Assert\NotNull]
    public \DateTimeImmutable $occurredAt;

    public ?\DateTimeImmutable $bookedAt = null;

    public ?string $description = null;

    /**
     * Ключ повтора формы создания: UUIDv7 выдаётся при показе формы и становится ID
     * транзакции — повторная отправка той же формы возвращает уже созданную транзакцию.
     */
    #[Assert\Uuid]
    public ?string $idempotencyKey = null;

    /**
     * Код источника импорта (для составного ключа идемпотентности).
     */
    public ?string $importSource = null;

    /**
     * Внешний идентификатор транзакции из источника (банк/файл и т.д.).
     */
    public ?string $externalId = null;

    /**
     * Дедупликационный хэш транзакции.
     */
    public ?string $dedupeHash = null;

    /** @var array<string, mixed>|null */
    public ?array $rawData = null;

    public ?string $payerInn = null;
    public ?string $payeeInn = null;
    public ?string $counterpartyNameRaw = null;
    public ?string $payerAccount = null;
    public ?string $payeeAccount = null;
    public ?string $payerBic = null;
    public ?string $payeeBank = null;
    public ?CounterpartyType $counterpartyType = null;

    public function __construct()
    {
    }
}
