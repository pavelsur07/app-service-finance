<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;
use Psr\Log\LoggerInterface;

/**
 * Потребитель диагностической задачи `echo`: путь Symfony → ai-service → Symfony
 * пройден, если результат дошёл сюда. Ничего не хранит; содержимое output не логируется.
 */
final readonly class EchoAiTaskResultConsumer implements AiTaskResultConsumerInterface
{
    public const TASK_TYPE = 'echo';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function supports(string $taskType): bool
    {
        return self::TASK_TYPE === $taskType;
    }

    public function consume(AiTaskResult $result): void
    {
        $this->logger->info('AI echo result received.', [
            'taskId' => $result->taskId,
            'companyId' => $result->companyId,
            'status' => $result->status->value,
            'models' => $result->models,
            'promptVersion' => $result->promptVersion,
            'errorCode' => $result->errorCode,
        ]);
    }
}
