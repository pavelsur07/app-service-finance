<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\CheckoutStatus;
use App\Billing\Repository\BillingCheckoutRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Попытка онлайн-оплаты тарифа. `id` задаёт форма (скрытый UUIDv7): ключ повтора, Idempotence-Key
 * ЮKassa и ID будущей `BillingPayment`. Сумма — из справочника на момент создания.
 */
#[ORM\Entity(repositoryClass: BillingCheckoutRepository::class)]
#[ORM\Table(name: 'billing_checkouts')]
#[ORM\UniqueConstraint(name: 'uniq_billing_checkouts_provider_payment', columns: ['provider_payment_id'])]
#[ORM\Index(name: 'idx_billing_checkouts_company_created', columns: ['company_id', 'created_at'])]
#[ORM\Index(name: 'idx_billing_checkouts_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_billing_checkouts_tariff', columns: ['tariff_id'])]
class BillingCheckout
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Tariff::class)]
    #[ORM\JoinColumn(name: 'tariff_id', nullable: false)]
    private Tariff $tariff;

    #[ORM\Column]
    private int $months;

    #[ORM\Column(name: 'amount_kopecks')]
    private int $amountKopecks;

    #[ORM\Column(length: 32, enumType: CheckoutStatus::class)]
    private CheckoutStatus $status = CheckoutStatus::PENDING;

    #[ORM\Column(name: 'provider_payment_id', length: 64, nullable: true)]
    private ?string $providerPaymentId = null;

    #[ORM\Column(name: 'confirmation_url', length: 2048, nullable: true)]
    private ?string $confirmationUrl = null;

    #[ORM\Column(name: 'failure_reason', length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $companyId, Tariff $tariff, int $months, int $amountKopecks, \DateTimeImmutable $now)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::false($tariff->isPerpetual(), 'Бессрочный тариф не оплачивается.');
        Assert::range($months, 1, 36);
        Assert::greaterThan($amountKopecks, 0);

        $this->id = $id;
        $this->companyId = $companyId;
        $this->tariff = $tariff;
        $this->months = $months;
        $this->amountKopecks = $amountKopecks;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** Платёж в ЮKassa создан: его ID и ссылка, куда отправить клиента. */
    public function attachProviderPayment(string $providerPaymentId, string $confirmationUrl, \DateTimeImmutable $now): void
    {
        Assert::notEmpty($providerPaymentId);
        Assert::startsWith($confirmationUrl, 'https://');
        if (null !== $this->providerPaymentId) {
            Assert::same($providerPaymentId, $this->providerPaymentId, 'К оплате уже привязан другой платёж ЮKassa.');
        }

        $this->providerPaymentId = $providerPaymentId;
        $this->confirmationUrl = $confirmationUrl;
        $this->updatedAt = $now;
    }

    public function markSucceeded(\DateTimeImmutable $now): void
    {
        $this->finish(CheckoutStatus::SUCCEEDED, $now);
    }

    public function markCanceled(\DateTimeImmutable $now): void
    {
        $this->finish(CheckoutStatus::CANCELED, $now);
    }

    /**
     * `failed_to_apply` пишет `ApplyYooKassaPaymentAction` через DBAL после отката зачисления
     * (EntityManager к тому моменту может быть закрыт) — здесь только успех и отмена.
     */
    private function finish(CheckoutStatus $status, \DateTimeImmutable $now): void
    {
        if ($this->status === $status) {
            return;
        }
        Assert::false($this->status->isFinal(), sprintf('Оплата уже в статусе «%s».', $this->status->value));

        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getTariff(): Tariff
    {
        return $this->tariff;
    }

    public function getMonths(): int
    {
        return $this->months;
    }

    public function getAmountKopecks(): int
    {
        return $this->amountKopecks;
    }

    public function getStatus(): CheckoutStatus
    {
        return $this->status;
    }

    public function getProviderPaymentId(): ?string
    {
        return $this->providerPaymentId;
    }

    public function getConfirmationUrl(): ?string
    {
        return $this->confirmationUrl;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
