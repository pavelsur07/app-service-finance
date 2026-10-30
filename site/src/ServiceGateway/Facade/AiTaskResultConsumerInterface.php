<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

use App\ServiceGateway\Application\DTO\AiTaskResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Потребитель результатов задач своего task_type (подсказки Cash и т. п.).
 *
 * Вызывается импортом внутри его транзакции: только persist()/remove(), без flush().
 * Идемпотентен по `taskId`: до ack сервис отдаёт тот же результат повторно.
 * `accepted` применяется только при включённом автоприменении компании (ТЗ §6).
 */
#[AutoconfigureTag(self::TAG)]
interface AiTaskResultConsumerInterface
{
    public const TAG = 'app.service_gateway.ai_task_result_consumer';

    public function supports(string $taskType): bool;

    public function consume(AiTaskResult $result): void;
}
