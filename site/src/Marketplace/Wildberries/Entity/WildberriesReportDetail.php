<?php

namespace App\Marketplace\Wildberries\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wildberries_report_details')]
#[ORM\UniqueConstraint(name: 'uniq_company_rrd', columns: ['company_id', 'rrd_id'])]
#[ORM\Index(name: 'idx_wb_report_detail_import_id', columns: ['import_id'])]
class WildberriesReportDetail
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(name: 'import_id', type: 'guid')]
    private string $importId;

    // WB identifiers
    #[ORM\Column(name: 'realizationreport_id', type: 'bigint', nullable: true)]
    private ?int $realizationreportId = null;

    #[ORM\Column(name: 'rrd_id', type: 'bigint', nullable: false)]
    private int $rrdId;

    // Dating
    #[ORM\Column(name: 'sale_dt', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $saleDt = null;

    #[ORM\Column(name: 'rr_dt', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rrDt = null;

    #[ORM\Column(name: 'order_dt', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $orderDt = null;

    // Nomenclature
    #[ORM\Column(name: 'nm_id', type: 'bigint', nullable: true)]
    private ?int $nmId = null;

    #[ORM\Column(name: 'barcode', type: 'string', length: 255, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(name: 'subject_name', type: 'string', length: 255, nullable: true)]
    private ?string $subjectName = null;

    #[ORM\Column(name: 'brand_name', type: 'string', length: 255, nullable: true)]
    private ?string $brandName = null;

    // Amounts (decimal(15,2) like in WildberriesSale)
    #[ORM\Column(name: 'retail_price', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $retailPrice = null;

    #[ORM\Column(name: 'retail_price_with_disc_rub', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $retailPriceWithDiscRub = null;

    #[ORM\Column(name: 'ppvz_sales_commission', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $ppvzSalesCommission = null;

    #[ORM\Column(name: 'ppvz_for_pay', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $ppvzForPay = null;

    #[ORM\Column(name: 'delivery_rub', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $deliveryRub = null;

    #[ORM\Column(name: 'storage_fee', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $storageFee = null;

    #[ORM\Column(name: 'acceptance', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $acceptance = null;

    #[ORM\Column(name: 'penalty', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $penalty = null;

    #[ORM\Column(name: 'acquiring_fee', type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $acquiringFee = null;

    // Other short fields from WB
    #[ORM\Column(name: 'site_country', type: 'string', length: 255, nullable: true)]
    private ?string $siteCountry = null;

    #[ORM\Column(name: 'supplier_oper_name', type: 'string', length: 255, nullable: true)]
    private ?string $supplierOperName = null;

    #[ORM\Column(name: 'doc_type_name', type: 'string', length: 255, nullable: true)]
    private ?string $docTypeName = null;

    #[ORM\Column(name: 'status_updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $statusUpdatedAt = null;

    // RAW row as in WildberriesSale
    #[ORM\Column(name: 'raw', type: 'json', nullable: false)]
    private array $raw = [];

    // Timestamps (optional but useful like in sales)
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Getters / Setters

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getImportId(): string
    {
        return $this->importId;
    }

    public function setImportId(string $importId): self
    {
        $this->importId = $importId;

        return $this;
    }

    public function getRealizationreportId(): ?int
    {
        return $this->realizationreportId;
    }

    public function setRealizationreportId(?int $realizationreportId): self
    {
        $this->realizationreportId = $realizationreportId;

        return $this;
    }

    public function getRrdId(): int
    {
        return $this->rrdId;
    }

    public function setRrdId(int $rrdId): self
    {
        $this->rrdId = $rrdId;

        return $this;
    }

    public function getSaleDt(): ?\DateTimeImmutable
    {
        return $this->saleDt;
    }

    public function setSaleDt(?\DateTimeImmutable $saleDt): self
    {
        $this->saleDt = $saleDt;

        return $this;
    }

    public function getRrDt(): ?\DateTimeImmutable
    {
        return $this->rrDt;
    }

    public function setRrDt(?\DateTimeImmutable $rrDt): self
    {
        $this->rrDt = $rrDt;

        return $this;
    }

    public function getOrderDt(): ?\DateTimeImmutable
    {
        return $this->orderDt;
    }

    public function setOrderDt(?\DateTimeImmutable $orderDt): self
    {
        $this->orderDt = $orderDt;

        return $this;
    }

    public function getNmId(): ?int
    {
        return $this->nmId;
    }

    public function setNmId(?int $nmId): self
    {
        $this->nmId = $nmId;

        return $this;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): self
    {
        $this->barcode = $barcode;

        return $this;
    }

    public function getSubjectName(): ?string
    {
        return $this->subjectName;
    }

    public function setSubjectName(?string $subjectName): self
    {
        $this->subjectName = $subjectName;

        return $this;
    }

    public function getBrandName(): ?string
    {
        return $this->brandName;
    }

    public function setBrandName(?string $brandName): self
    {
        $this->brandName = $brandName;

        return $this;
    }

    public function getRetailPrice(): ?string
    {
        return $this->retailPrice;
    }

    public function setRetailPrice(?string $retailPrice): self
    {
        $this->retailPrice = $retailPrice;

        return $this;
    }

    public function getRetailPriceWithDiscRub(): ?string
    {
        return $this->retailPriceWithDiscRub;
    }

    public function setRetailPriceWithDiscRub(?string $retailPriceWithDiscRub): self
    {
        $this->retailPriceWithDiscRub = $retailPriceWithDiscRub;

        return $this;
    }

    public function getPpvzSalesCommission(): ?string
    {
        return $this->ppvzSalesCommission;
    }

    public function setPpvzSalesCommission(?string $ppvzSalesCommission): self
    {
        $this->ppvzSalesCommission = $ppvzSalesCommission;

        return $this;
    }

    public function getPpvzForPay(): ?string
    {
        return $this->ppvzForPay;
    }

    public function setPpvzForPay(?string $ppvzForPay): self
    {
        $this->ppvzForPay = $ppvzForPay;

        return $this;
    }

    public function getDeliveryRub(): ?string
    {
        return $this->deliveryRub;
    }

    public function setDeliveryRub(?string $deliveryRub): self
    {
        $this->deliveryRub = $deliveryRub;

        return $this;
    }

    public function getStorageFee(): ?string
    {
        return $this->storageFee;
    }

    public function setStorageFee(?string $storageFee): self
    {
        $this->storageFee = $storageFee;

        return $this;
    }

    public function getAcceptance(): ?string
    {
        return $this->acceptance;
    }

    public function setAcceptance(?string $acceptance): self
    {
        $this->acceptance = $acceptance;

        return $this;
    }

    public function getPenalty(): ?string
    {
        return $this->penalty;
    }

    public function setPenalty(?string $penalty): self
    {
        $this->penalty = $penalty;

        return $this;
    }

    public function getAcquiringFee(): ?string
    {
        return $this->acquiringFee;
    }

    public function setAcquiringFee(?string $acquiringFee): self
    {
        $this->acquiringFee = $acquiringFee;

        return $this;
    }

    public function getSiteCountry(): ?string
    {
        return $this->siteCountry;
    }

    public function setSiteCountry(?string $siteCountry): self
    {
        $this->siteCountry = $siteCountry;

        return $this;
    }

    public function getSupplierOperName(): ?string
    {
        return $this->supplierOperName;
    }

    public function setSupplierOperName(?string $supplierOperName): self
    {
        $this->supplierOperName = $supplierOperName;

        return $this;
    }

    public function getDocTypeName(): ?string
    {
        return $this->docTypeName;
    }

    public function setDocTypeName(?string $docTypeName): self
    {
        $this->docTypeName = $docTypeName;

        return $this;
    }

    public function getStatusUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->statusUpdatedAt;
    }

    public function setStatusUpdatedAt(?\DateTimeImmutable $statusUpdatedAt): self
    {
        $this->statusUpdatedAt = $statusUpdatedAt;

        return $this;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function setRaw(array $raw): self
    {
        $this->raw = $raw;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
