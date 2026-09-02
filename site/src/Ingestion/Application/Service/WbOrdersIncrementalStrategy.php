<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Action\EnsureOrdersCursorAction;
use App\Ingestion\Application\Command\EnsureOrdersCursorCommand;
use App\Ingestion\Enum\IngestSource;
use Symfony\Component\Clock\ClockInterface;

/**
 * Часовая стратегия для заказов Wildberries.
 *
 * Один класс на оба потока: marketplace и statistics отличаются только строкой
 * ресурса, поэтому в DI регистрируются два сервиса с разным `$resourceType`.
 */
final readonly class WbOrdersIncrementalStrategy extends AbstractHourlyCursorIncrementalStrategy
{
    public function __construct(
        private EnsureOrdersCursorAction $ensureCursorAction,
        private string $resourceType,
        ClockInterface $clock,
        ?int $minIntervalMinutes = null,
    ) {
        // Значение по умолчанию задаёт база: порог связан с периодом крона,
        // а не с маркетплейсом.
        null === $minIntervalMinutes
            ? parent::__construct($clock)
            : parent::__construct($clock, $minIntervalMinutes);
    }

    public function source(): IngestSource
    {
        return IngestSource::WILDBERRIES;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function supportsConnection(array $connection): bool
    {
        return IngestSource::WILDBERRIES->value === (string) $connection['marketplace'];
    }

    public function ensureCursor(string $companyId, string $connectionRef): void
    {
        ($this->ensureCursorAction)(new EnsureOrdersCursorCommand($companyId, $connectionRef, $this->resourceType));
    }
}
