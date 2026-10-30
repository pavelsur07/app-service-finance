<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Action;

use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Application\DTO\ImportAiResultsResult;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;
use App\ServiceGateway\Infrastructure\Client\AiServiceClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Забор готовых результатов ai-service (pull + ack, services/AGENTS.md §3).
 *
 * По странице: HTTP вне транзакции → потребители в одной транзакции → ack после commit.
 * Сбой ack — сервис отдаст ту же страницу снова, потребители идемпотентны по taskId.
 * Результат без потребителя не подтверждается и остаётся в сервисе (карантин, не skip).
 */
final readonly class ImportAiResultsAction
{
    public const PAGE_SIZE = 100;
    public const MAX_PAGES = 20;

    /**
     * @param iterable<AiTaskResultConsumerInterface> $consumers
     */
    public function __construct(
        private AiServiceClientInterface $client,
        #[AutowireIterator(AiTaskResultConsumerInterface::TAG)]
        private iterable $consumers,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): ImportAiResultsResult
    {
        $received = 0;
        $acked = 0;
        /** @var array<string, int> $unhandledByType */
        $unhandledByType = [];
        $after = null;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $results = $this->client->fetchResults($after, self::PAGE_SIZE);
            if ([] === $results->items) {
                break;
            }
            $received += \count($results->items);

            /** @var list<string> $handled */
            $handled = $this->em->wrapInTransaction(function () use ($results, &$unhandledByType): array {
                $handled = [];
                foreach ($results->items as $result) {
                    $consumer = $this->consumerFor($result);
                    if (null === $consumer) {
                        $unhandledByType[$result->taskType] = ($unhandledByType[$result->taskType] ?? 0) + 1;
                        continue;
                    }
                    $consumer->consume($result);
                    $handled[] = $result->taskId;
                }

                return $handled;
            });

            if ([] !== $handled) {
                $acked += $this->client->ack($handled);
            }
            $after = $results->nextCursor;
            if (null === $after) {
                break;
            }
        }

        $unhandled = array_sum($unhandledByType);
        if ($unhandled > 0) {
            // Один агрегированный error за прогон: результат пришёл, а принять его некому.
            $this->logger->error('AI results without a consumer were left unacknowledged.', [
                'count' => $unhandled,
                'taskTypes' => array_keys($unhandledByType),
            ]);
        }

        return new ImportAiResultsResult($received, $acked, $unhandled);
    }

    private function consumerFor(AiTaskResult $result): ?AiTaskResultConsumerInterface
    {
        foreach ($this->consumers as $consumer) {
            if ($consumer->supports($result->taskType)) {
                return $consumer;
            }
        }

        return null;
    }
}
