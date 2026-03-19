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
 * Несколько строк с одним SKU за один период = несколько отгрузок.
 *
 * Выручка = delivery_commission.price_per_instance × delivery_commission.quantity
 * (цена единицы покупателю с учётом СПП скидки).
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

    /** SKU маркетплейса */
    #[ORM\Column(type: 'string', length: 50)]
    private string $sku;

    /** Артикул продавца */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $offerId;

    /** Наименование товара */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $name;

    /**
     * Цена единицы покупателю с учётом СПП скидки.
     * Источник: delivery_commission.price_per_instance из /v2/finance/realization.
     *
     * Колонка намеренно сохраняет старое имя seller_price_per_instance —
     * миграция не требуется.
     */
    #[ORM\Column(name: 'seller_price_per_instance', type: 'decimal', precision: 12, scale: 2)]
    private string $pricePerInstance;

    /** Количество */
    #[ORM\Column(type: 'integer')]
    private int $quantity;

    /** Итого выручка = price_per_instance × quantity */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $totalAmount;

    /** Начало периода реализации */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    /** Конец периода реализации */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    /**
     * ID документа ОПиУ — заполняется при закрытии месяца.
     * NULL = не обработано.
     */
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
    public function getPeriodFrom(): \DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): \DateTimeImmutable { return $this->periodTo; }
    public function getPlDocumentId(): ?string { return $this->plDocumentId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setListing(?MarketplaceListing $listing): void { $this->listing = $listing; }
    public function setOfferId(?string $offerId): void { $this->offerId = $offerId; }
    public function setName(?string $name): void { $this->name = $name; }
    public function setPlDocumentId(?string $plDocumentId): void { $this->plDocumentId = $plDocumentId; }
}
