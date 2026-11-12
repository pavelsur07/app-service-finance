<?php

declare(strict_types=1);

namespace App\Inventory\Enum;

enum StockStatus: string
{
    case Available = 'available';
    case InTransitToCustomer = 'in_transit_to_customer';
    case InTransitFromCustomer = 'in_transit_from_customer';
    case OnAcceptance = 'on_acceptance';
    case Defect = 'defect';
    case Blocked = 'blocked';
    /** Товар активной заявки на поставку FBO Ozon: едет на склад, в остаток не входит. */
    case InSupply = 'in_supply';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'На складе',
            self::InTransitToCustomer => 'В пути к клиенту',
            self::InTransitFromCustomer => 'В пути от клиента',
            self::OnAcceptance => 'На приёмке',
            self::Defect => 'Брак',
            self::Blocked => 'Заблокировано',
            self::InSupply => 'В поставке',
        };
    }
}
