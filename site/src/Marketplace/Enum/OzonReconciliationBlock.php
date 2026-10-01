<?php

declare(strict_types=1);

namespace App\Marketplace\Enum;

/**
 * Блок сверки с Ozon. Блоки затрат повторяют группы «Детализации начислений»
 * Ozon (`OzonCostCategory::$xlsxGroup`), поэтому названия знакомы продавцу.
 */
enum OzonReconciliationBlock: string
{
    case SALES = 'sales';
    case RETURNS = 'returns';
    case COMMISSION = 'commission';
    case LOGISTICS = 'logistics';
    case FBO_SERVICES = 'fbo_services';
    case PARTNER_SERVICES = 'partner_services';
    case ADVERTISING = 'advertising';
    case COMPENSATIONS = 'compensations';
    case OTHER_FEES = 'other_fees';
    case UNRECOGNIZED = 'unrecognized';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALES => 'Продажи',
            self::RETURNS => 'Возвраты',
            self::COMMISSION => 'Вознаграждение Ozon',
            self::LOGISTICS => 'Услуги доставки',
            self::FBO_SERVICES => 'Услуги FBO',
            self::PARTNER_SERVICES => 'Услуги партнёров',
            self::ADVERTISING => 'Продвижение и реклама',
            self::COMPENSATIONS => 'Компенсации и декомпенсации',
            self::OTHER_FEES => 'Другие услуги и штрафы',
            self::UNRECOGNIZED => 'Нераспознанные услуги',
        };
    }

    public function isCostBlock(): bool
    {
        return self::SALES !== $this && self::RETURNS !== $this;
    }
}
