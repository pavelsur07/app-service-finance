<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Enum;

enum UnitEconomyCostType: string
{
    case LOGISTICS_TO = 'logistics_to';
    case LOGISTICS_BACK = 'logistics_back';
    case STORAGE = 'storage';
    case ADVERTISING_CPC = 'advertising_cpc';
    case ADVERTISING_OTHER = 'advertising_other';
    case ADVERTISING_EXTERNAL = 'advertising_external';
    case COMMISSION = 'commission';
    case ACQUIRING = 'acquiring';
    case PENALTIES = 'penalties';
    case ACCEPTANCE = 'acceptance';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::LOGISTICS_TO         => 'Логистика (доставка)',
            self::LOGISTICS_BACK       => 'Логистика (возврат)',
            self::STORAGE              => 'Хранение',
            self::ADVERTISING_CPC      => 'Реклама (CPC)',
            self::ADVERTISING_OTHER    => 'Реклама (прочая)',
            self::ADVERTISING_EXTERNAL => 'Реклама (внешняя)',
            self::COMMISSION           => 'Комиссия',
            self::ACQUIRING            => 'Эквайринг',
            self::PENALTIES            => 'Штрафы',
            self::ACCEPTANCE           => 'Приемка',
            self::OTHER                => 'Прочее',
        };
    }

    public function isAdvertising(): bool
    {
        return match ($this) {
            self::ADVERTISING_CPC, self::ADVERTISING_OTHER, self::ADVERTISING_EXTERNAL => true,
            default => false,
        };
    }
}
