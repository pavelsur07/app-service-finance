<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Последние `X-TraceId` ответов Альфы — для отчёта проверки песочницы и обращений в
 * поддержку банка. Буфер ограничен и сбрасывается между сообщениями воркера.
 */
final class AlfaTraceIdRecorder implements ResetInterface
{
    private const LIMIT = 50;

    /** @var list<string> */
    private array $traceIds = [];

    public function record(?string $traceId): void
    {
        if (null === $traceId || '' === $traceId) {
            return;
        }
        $this->traceIds[] = $traceId;
        if (\count($this->traceIds) > self::LIMIT) {
            array_shift($this->traceIds);
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->traceIds;
    }

    public function reset(): void
    {
        $this->traceIds = [];
    }
}
