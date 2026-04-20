<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Exception;

/**
 * Transient-ошибка: очередь отчётов Ozon Performance API перегружена, отчёт
 * не начал обработку за отведённое время (state=NOT_STARTED удерживается
 * дольше {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient::POLL_NOT_STARTED_MAX_SECONDS}).
 *
 * Extends \RuntimeException (а не {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException}):
 * это НЕ permanent denial — повторная попытка через час / день может пройти,
 * когда Ozon Performance справится с back-pressure.
 *
 * Handler ({@see \App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler})
 * оборачивает это исключение в {@see \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException}:
 * Messenger-retry по расписанию (секунды / минуты) бессмысленен — деградация
 * Ozon обычно длится часы. Пользователь повторяет загрузку вручную из UI.
 */
final class OzonStatisticsQueueFullException extends \RuntimeException
{
    public function __construct(
        private readonly string $reportUuid,
        private readonly int $waitedSeconds,
    ) {
        parent::__construct(sprintf(
            'Очередь отчётов Ozon Performance перегружена: отчёт %s не начал обработку за %d секунд. Повторите позже.',
            $reportUuid,
            $waitedSeconds,
        ));
    }

    public function getReportUuid(): string
    {
        return $this->reportUuid;
    }

    public function getWaitedSeconds(): int
    {
        return $this->waitedSeconds;
    }
}
