<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Документ закрытия месяца маркетплейса.
 *
 * Поэтапное закрытие:
 *   Этап 1 (sales_returns) — продажи и возвраты
 *   Этап 2 (costs)         — затраты
 *
 * Каждый этап закрывается независимо.
 * Переоткрытие возможно только если дата окончания периода
 * позже Company::financeLockBefore.
 */
#[ORM\Entity(repositoryClass: MarketplaceMonthCloseRepository::class)]
#[ORM\Table(name: 'marketplace_month_closes')]
#[ORM\UniqueConstraint(
    name: 'uniq_month_close',
    columns: ['company_id', 'marketplace', 'year', 'month'],
)]
#[ORM\Index(columns: ['company_id', 'marketplace', 'year', 'month'], name: 'idx_month_close_lookup')]
class MarketplaceMonthClose
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'smallint')]
    private int $year;

    #[ORM\Column(type: 'smallint')]
    private int $month;

    // --- Этап 1: Продажи и возвраты ---

    #[ORM\Column(type: 'string', enumType: MonthCloseStageStatus::class)]
    private MonthCloseStageStatus $stageSalesReturnsStatus = MonthCloseStageStatus::PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $stageSalesReturnsClosedAt = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $stageSalesReturnsClosedByUserId = null;

    /** ID документов ОПиУ созданных на этом этапе */
    #[ORM\Column(name: 'stage_sales_returns_pl_document_ids', type: 'json', nullable: true)]
    private ?array $stageSalesReturnsPLDocumentIds = null;

    /** Снимок результатов preflight на момент закрытия */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $stageSalesReturnsPreflightSnapshot = null;

    // --- Этап 2: Затраты ---

    #[ORM\Column(type: 'string', enumType: MonthCloseStageStatus::class)]
    private MonthCloseStageStatus $stageCostsStatus = MonthCloseStageStatus::PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $stageCostsClosedAt = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $stageCostsClosedByUserId = null;

    #[ORM\Column(name: 'stage_costs_pl_document_ids', type: 'json', nullable: true)]
    private ?array $stageCostsPLDocumentIds = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $stageCostsPreflightSnapshot = null;

    // --- Служебные поля ---

    /**
     * Произвольные настройки периода закрытия.
     * Используется для хранения результата сверки с xlsx без новой миграции.
     *
     * Структура costs_reconciliation:
     *   status: matched | mismatch
     *   api_net_amount, xlsx_total, delta
     *   file_path, reconciled_at
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceType $marketplace,
        int $year,
        int $month,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::range($month, 1, 12);
        Assert::greaterThan($year, 2000);

        $this->id          = $id;
        $this->companyId   = $companyId;
        $this->marketplace = $marketplace;
        $this->year        = $year;
        $this->month       = $month;
        $this->createdAt   = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
    }

    // --- Бизнес-методы ---

    public function closeStage(
        CloseStage $stage,
        string $userId,
        array $plDocumentIds,
        array $preflightSnapshot,
    ): void {
        $this->updatedAt = new \DateTimeImmutable();

        match ($stage) {
            CloseStage::SALES_RETURNS => $this->closeSalesReturns($userId, $plDocumentIds, $preflightSnapshot),
            CloseStage::COSTS         => $this->closeCosts($userId, $plDocumentIds, $preflightSnapshot),
        };
    }

    /**
     * Переоткрыть этап.
     *
     * Полностью сбрасывает все поля этапа: статус, даты, userId, IDs документов и snapshot.
     * Это гарантирует, что при повторном закрытии getStagePLDocumentIds() не вернёт
     * устаревшие ID от предыдущего (уже удалённого) PLDocument.
     *
     * Вызывается ПОСЛЕ того как FinanceFacade::deletePLDocument() удалил старые документы.
     */
    public function reopenStage(CloseStage $stage): void
    {
        $this->updatedAt = new \DateTimeImmutable();

        match ($stage) {
            CloseStage::SALES_RETURNS => $this->doReopenSalesReturns(),
            CloseStage::COSTS         => $this->doReopenCosts(),
        };
    }

    public function getStageStatus(CloseStage $stage): MonthCloseStageStatus
    {
        return match ($stage) {
            CloseStage::SALES_RETURNS => $this->stageSalesReturnsStatus,
            CloseStage::COSTS         => $this->stageCostsStatus,
        };
    }

    public function isStageClosed(CloseStage $stage): bool
    {
        return $this->getStageStatus($stage)->isClosed();
    }

    /**
     * @return string[]
     */
    public function getStagePLDocumentIds(CloseStage $stage): array
    {
        $ids = match ($stage) {
            CloseStage::SALES_RETURNS => $this->stageSalesReturnsPLDocumentIds,
            CloseStage::COSTS         => $this->stageCostsPLDocumentIds,
        };

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
    }

    public function isFullyClosed(): bool
    {
        return $this->stageSalesReturnsStatus->isClosed()
            && $this->stageCostsStatus->isClosed();
    }

    /**
     * Последний день закрываемого месяца.
     * Используется для проверки financeLockBefore.
     */
    public function getPeriodEnd(): \DateTimeImmutable
    {
        $firstDay = new \DateTimeImmutable(
            sprintf('%d-%02d-01', $this->year, $this->month)
        );

        return $firstDay->modify('last day of this month');
    }

    // --- Getters ---

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getYear(): int { return $this->year; }
    public function getMonth(): int { return $this->month; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getStageSalesReturnsStatus(): MonthCloseStageStatus { return $this->stageSalesReturnsStatus; }
    public function getStageSalesReturnsClosedAt(): ?\DateTimeImmutable { return $this->stageSalesReturnsClosedAt; }
    public function getStageSalesReturnsPLDocumentIds(): ?array { return $this->stageSalesReturnsPLDocumentIds; }

    public function getStageCostsStatus(): MonthCloseStageStatus { return $this->stageCostsStatus; }
    public function getStageCostsClosedAt(): ?\DateTimeImmutable { return $this->stageCostsClosedAt; }
    public function getStageCostsPLDocumentIds(): ?array { return $this->stageCostsPLDocumentIds; }

    // --- Сверка с xlsx ---

    public function getCostsReconciliation(): ?array
    {
        return $this->settings['costs_reconciliation'] ?? null;
    }

    public function setCostsReconciliation(array $reconciliation): void
    {
        $settings = $this->settings ?? [];
        $settings['costs_reconciliation'] = $reconciliation;
        $this->settings  = $settings;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function hasCostsReconciliation(): bool
    {
        return isset($this->settings['costs_reconciliation']);
    }

    public function getCostsReconciliationStatus(): ?string
    {
        return $this->settings['costs_reconciliation']['status'] ?? null;
    }

    // --- Private helpers ---

    private function closeSalesReturns(string $userId, array $plDocumentIds, array $preflightSnapshot): void
    {
        $this->stageSalesReturnsStatus            = MonthCloseStageStatus::CLOSED;
        $this->stageSalesReturnsClosedAt          = new \DateTimeImmutable();
        $this->stageSalesReturnsClosedByUserId    = $userId;
        $this->stageSalesReturnsPLDocumentIds     = $plDocumentIds;
        $this->stageSalesReturnsPreflightSnapshot = $preflightSnapshot;
    }

    private function closeCosts(string $userId, array $plDocumentIds, array $preflightSnapshot): void
    {
        $this->stageCostsStatus            = MonthCloseStageStatus::CLOSED;
        $this->stageCostsClosedAt          = new \DateTimeImmutable();
        $this->stageCostsClosedByUserId    = $userId;
        $this->stageCostsPLDocumentIds     = $plDocumentIds;
        $this->stageCostsPreflightSnapshot = $preflightSnapshot;
    }

    /**
     * Сбрасывает все поля этапа SALES_RETURNS в начальное состояние.
     * Вызывается при переоткрытии.
     */
    private function doReopenSalesReturns(): void
    {
        $this->stageSalesReturnsStatus            = MonthCloseStageStatus::REOPENED;
        $this->stageSalesReturnsClosedAt          = null;
        $this->stageSalesReturnsClosedByUserId    = null;
        $this->stageSalesReturnsPLDocumentIds     = null;  // ← ключевой сброс
        $this->stageSalesReturnsPreflightSnapshot = null;
    }

    /**
     * Сбрасывает все поля этапа COSTS в начальное состояние.
     * Вызывается при переоткрытии.
     */
    private function doReopenCosts(): void
    {
        $this->stageCostsStatus            = MonthCloseStageStatus::REOPENED;
        $this->stageCostsClosedAt          = null;
        $this->stageCostsClosedByUserId    = null;
        $this->stageCostsPLDocumentIds     = null;  // ← ключевой сброс
        $this->stageCostsPreflightSnapshot = null;
    }
}
