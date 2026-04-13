<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Exception;

/**
 * Сигнализирует, что AdRawDocument не может быть обработан, потому что его
 * уже обработал другой worker, либо он был удалён между dispatch и handler.
 *
 * Специфический тип исключения введён, чтобы async-обработчик
 * ({@see \App\MarketplaceAds\MessageHandler\ProcessAdRawDocumentHandler})
 * мог отличить реальную гонку состояний от действительно аномальных
 * DomainException (например, отсутствие парсера площадки — это баг
 * конфигурации, а не гонка, и такое исключение нельзя молча поглощать).
 */
final class AdRawDocumentAlreadyProcessedException extends \DomainException
{
}
