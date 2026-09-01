<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Action\EnsureOrdersCursorAction;
use App\Ingestion\Application\Command\EnsureOrdersCursorCommand;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Clock\ClockInterface;

/**
 * Часовая стратегия для заказов Ozon.
 *
 * Один класс на обе схемы: они отличаются только строкой ресурса, поэтому в
 * DI регистрируются два сервиса с разным `$resourceType`. Копировать класс
 * ради константы значило бы плодить места, где расходится логика.
 */
final readonly class OzonOrdersIncrementalStrategy extends AbstractHourlyCursorIncrementalStrategy
{
    public function __construct(
        private EnsureOrdersCursorAction $ensureCursorAction,
        private string $resourceType,
        ClockInterface $clock,
        int $minIntervalMinutes = 60,
    ) {
        parent::__construct($clock, $minIntervalMinutes);
    }

    public function source(): IngestSource
    {
        return IngestSource::OZON;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function supportsConnection(array $connection): bool
    {
        return IngestSource::OZON->value === (string) $connection['marketplace'];
    }

    public function ensureCursor(string $companyId, string $connectionRef): void
    {
        ($this->ensureCursorAction)(new EnsureOrdersCursorCommand($companyId, $connectionRef, $this->resourceType));
    }
}
