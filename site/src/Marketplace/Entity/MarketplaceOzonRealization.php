<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Денормализованная строка из отчёта реализации Ozon.
 *
 * Источник: marketplace_raw_documents (document_type = 'realization')
 * Поле raw_data.result.rows[].
 *
 * Одна строка = один SKU за период реализации.
 *
 * Выручка (продажа):
 *   delivery_commission.price_per_instance × delivery_commission.quantity
 *   → поля: pricePerInstance, quantity, totalAmount
 *
 * Возврат с СПП:
 *   return_commission.price_per_instance × return_commission.quantity
 *   → поля: returnPricePerInstance, returnQuantity, returnAmount
 *   Null если return_commission отсутствует в строке реализации.
 *
 * pl_document_id — заполняется при закрытии месяца.
 */
#[ORM\Entity(repositoryClass: MarketplaceOzonRealizationRepository::class)]
#[ORM\Table(name: 'marketplace_ozon_realizations')]
#[ORM\Index(columns: ['company_id', 'period_from', 'period_to'], name: 'idx_ozon_realization_period')]
#[ORM\Index(columns: ['company_id', 'pl_document_id'], name: 'idx_ozon_realization_pl_doc')]
class MarketplaceOzonRealization
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MarketplaceListing $listing;

    #[ORM\ManyToOne(targetEntity: MarketplaceRawDocument::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MarketplaceRawDocument $rawDocument;

    #[ORM\Column(type: 'string', length: 50)]
    private string $sku;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $offerId;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $name;

    /**
     * Цена единицы покупателю с учётом СПП.
     * Источник: delivery_commission.price_per_instance.
     * Колонка БД сохраняет имя seller_price_per_instance из исходной миграции.
     */
    #[ORM\Column(name: 'seller_price_per_instance', type: 'decimal', precision: 12, scale: 2)]
    private string $pricePerInstance;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    /** Итого выручка = pricePerInstance × quantity */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $totalAmount;

    // -------------------------------------------------------------------------
    // Возврат (return_commission) — nullable
    // -------------------------------------------------------------------------

    /**
     * Цена единицы возврата с учётом СПП.
     * Источник: return_commission.price_per_instance.
     * Null если в строке реализации return_commission = null.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $returnPricePerInstance = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $returnQuantity = null;

    /**
     * Итого сумма возврата = returnPricePerInstance × returnQuantity.
     * Источник суммы для AmountSource::RETURN_REALIZATION.
     */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $returnAmount = null;

    // -------------------------------------------------------------------------

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $plDocumentId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceRawDocument $rawDocument,
        string $sku,
        string $pricePerInstance,
        int $quantity,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id               = $id;
        $this->companyId        = $companyId;
        $this->rawDocument      = $rawDocument;
        $this->sku              = $sku;
        $this->pricePerInstance = $pricePerInstance;
        $this->quantity         = $quantity;
        $this->periodFrom       = $periodFrom;
        $this->periodTo         = $periodTo;
        $this->totalAmount      = bcmul($pricePerInstance, (string) $quantity, 2);
        $this->createdAt        = new \DateTimeImmutable();
    }

    /**
     * Обновить данные продажи из delivery_commission.
     * Вызывается при переобработке если в БД хранились неверные данные
     * (старый код брал seller_price_per_instance вместо price_per_instance).
     * flush() — ответственность вызывающего кода.
     */
    public function updateDeliveryCommission(float $pricePerInstance, int $quantity): void
    {
        if ($pricePerInstance <= 0 || $quantity <= 0) {
            return;
        }

        $price = number_format($pricePerInstance, 2, '.', '');

        $this->pricePerInstance = $price;
        $this->quantity         = $quantity;
        $this->totalAmount      = bcmul($price, (string) $quantity, 2);
    }

    /**
     * Установить данные возврата из return_commission.
     * Вызывается при обработке строки реализации если return_commission != null.
     * flush() — ответственность вызывающего кода.
     */
    public function setReturnCommission(float $pricePerInstance, int $quantity): void
    {
        if ($pricePerInstance <= 0 || $quantity <= 0) {
            return;
        }

        $price = number_format($pricePerInstance, 2, '.', '');

        $this->returnPricePerInstance = $price;
        $this->returnQuantity         = $quantity;
        $this->returnAmount           = bcmul($price, (string) $quantity, 2);
    }

    public function hasReturn(): bool
    {
        return $this->returnAmount !== null;
    }

    // --- Getters ---

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getListing(): ?MarketplaceListing { return $this->listing; }
    public function getRawDocument(): MarketplaceRawDocument { return $this->rawDocument; }
    public function getSku(): string { return $this->sku; }
    public function getOfferId(): ?string { return $this->offerId; }
    public function getName(): ?string { return $this->name; }
    public function getPricePerInstance(): string { return $this->pricePerInstance; }
    public function getQuantity(): int { return $this->quantity; }
    public function getTotalAmount(): string { return $this->totalAmount; }
    public function getReturnPricePerInstance(): ?string { return $this->returnPricePerInstance; }
    public function getReturnQuantity(): ?int { return $this->returnQuantity; }
    public function getReturnAmount(): ?string { return $this->returnAmount; }
    public function getPeriodFrom(): \DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): \DateTimeImmutable { return $this->periodTo; }
    public function getPlDocumentId(): ?string { return $this->plDocumentId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setListing(?MarketplaceListing $listing): void { $this->listing = $listing; }
    public function setOfferId(?string $offerId): void { $this->offerId = $offerId; }
    public function setName(?string $name): void { $this->name = $name; }
    public function setPlDocumentId(?string $plDocumentId): void { $this->plDocumentId = $plDocumentId; }
}
