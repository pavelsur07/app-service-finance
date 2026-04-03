<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\ValueObject;

use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use Webmozart\Assert\Assert;

final readonly class CostBreakdown
{
    public function __construct(
        public string $logisticsTo,
        public string $logisticsBack,
        public string $storage,
        public string $advertisingCpc,
        public string $advertisingOther,
        public string $advertisingExternal,
        public string $commission,
        public string $acquiring,
        public string $penalties,
        public string $acceptance,
        public string $other,
    ) {
        Assert::greaterThanEq((float) $logisticsTo, 0.0);
        Assert::greaterThanEq((float) $logisticsBack, 0.0);
        Assert::greaterThanEq((float) $storage, 0.0);
        Assert::greaterThanEq((float) $advertisingCpc, 0.0);
        Assert::greaterThanEq((float) $advertisingOther, 0.0);
        Assert::greaterThanEq((float) $advertisingExternal, 0.0);
        Assert::greaterThanEq((float) $commission, 0.0);
        Assert::greaterThanEq((float) $acquiring, 0.0);
        Assert::greaterThanEq((float) $penalties, 0.0);
        Assert::greaterThanEq((float) $acceptance, 0.0);
        Assert::greaterThanEq((float) $other, 0.0);
    }

    public function total(): string
    {
        $sum = '0.00';
        $sum = bcadd($sum, $this->logisticsTo, 2);
        $sum = bcadd($sum, $this->logisticsBack, 2);
        $sum = bcadd($sum, $this->storage, 2);
        $sum = bcadd($sum, $this->advertisingCpc, 2);
        $sum = bcadd($sum, $this->advertisingOther, 2);
        $sum = bcadd($sum, $this->advertisingExternal, 2);
        $sum = bcadd($sum, $this->commission, 2);
        $sum = bcadd($sum, $this->acquiring, 2);
        $sum = bcadd($sum, $this->penalties, 2);
        $sum = bcadd($sum, $this->acceptance, 2);
        $sum = bcadd($sum, $this->other, 2);

        return $sum;
    }

    public function totalAdvertising(): string
    {
        return bcadd(
            $this->advertisingCpc,
            bcadd($this->advertisingOther, $this->advertisingExternal, 2),
            2,
        );
    }

    public function toArray(): array
    {
        return [
            UnitEconomyCostType::LOGISTICS_TO->value         => $this->logisticsTo,
            UnitEconomyCostType::LOGISTICS_BACK->value       => $this->logisticsBack,
            UnitEconomyCostType::STORAGE->value              => $this->storage,
            UnitEconomyCostType::ADVERTISING_CPC->value      => $this->advertisingCpc,
            UnitEconomyCostType::ADVERTISING_OTHER->value    => $this->advertisingOther,
            UnitEconomyCostType::ADVERTISING_EXTERNAL->value => $this->advertisingExternal,
            UnitEconomyCostType::COMMISSION->value           => $this->commission,
            UnitEconomyCostType::ACQUIRING->value            => $this->acquiring,
            UnitEconomyCostType::PENALTIES->value            => $this->penalties,
            UnitEconomyCostType::ACCEPTANCE->value           => $this->acceptance,
            UnitEconomyCostType::OTHER->value                => $this->other,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            logisticsTo:         $data[UnitEconomyCostType::LOGISTICS_TO->value]         ?? '0.00',
            logisticsBack:       $data[UnitEconomyCostType::LOGISTICS_BACK->value]       ?? '0.00',
            storage:             $data[UnitEconomyCostType::STORAGE->value]              ?? '0.00',
            advertisingCpc:      $data[UnitEconomyCostType::ADVERTISING_CPC->value]      ?? '0.00',
            advertisingOther:    $data[UnitEconomyCostType::ADVERTISING_OTHER->value]    ?? '0.00',
            advertisingExternal: $data[UnitEconomyCostType::ADVERTISING_EXTERNAL->value] ?? '0.00',
            commission:          $data[UnitEconomyCostType::COMMISSION->value]           ?? '0.00',
            acquiring:           $data[UnitEconomyCostType::ACQUIRING->value]            ?? '0.00',
            penalties:           $data[UnitEconomyCostType::PENALTIES->value]            ?? '0.00',
            acceptance:          $data[UnitEconomyCostType::ACCEPTANCE->value]           ?? '0.00',
            other:               $data[UnitEconomyCostType::OTHER->value]                ?? '0.00',
        );
    }

    public function hasData(): bool
    {
        return bccomp($this->total(), '0.00', 2) > 0;
    }

    public function getByType(UnitEconomyCostType $type): string
    {
        return match ($type) {
            UnitEconomyCostType::LOGISTICS_TO         => $this->logisticsTo,
            UnitEconomyCostType::LOGISTICS_BACK       => $this->logisticsBack,
            UnitEconomyCostType::STORAGE              => $this->storage,
            UnitEconomyCostType::ADVERTISING_CPC      => $this->advertisingCpc,
            UnitEconomyCostType::ADVERTISING_OTHER    => $this->advertisingOther,
            UnitEconomyCostType::ADVERTISING_EXTERNAL => $this->advertisingExternal,
            UnitEconomyCostType::COMMISSION           => $this->commission,
            UnitEconomyCostType::ACQUIRING            => $this->acquiring,
            UnitEconomyCostType::PENALTIES            => $this->penalties,
            UnitEconomyCostType::ACCEPTANCE           => $this->acceptance,
            UnitEconomyCostType::OTHER                => $this->other,
        };
    }
}
