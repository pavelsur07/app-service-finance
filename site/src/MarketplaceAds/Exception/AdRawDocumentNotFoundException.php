<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Exception;

/**
 * Сигнализирует, что запрошенный AdRawDocument не существует либо принадлежит
 * другой компании. Публичный API (контроллеры reprocess, delete и т.п.)
 * должен маппить это исключение на HTTP 404 без раскрытия деталей существования.
 */
final class AdRawDocumentNotFoundException extends \DomainException
{
}
