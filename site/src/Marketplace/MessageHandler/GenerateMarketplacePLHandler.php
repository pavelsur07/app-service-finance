<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\GeneratePLCommand;
use App\Marketplace\Application\GeneratePLFromMarketplaceAction;
use App\Marketplace\Message\GenerateMarketplacePLMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Обработчик асинхронного сообщения для генерации ОПиУ.
 *
 * Worker-safe: не зависит от HTTP-сессии, ActiveCompanyService не используется.
 */
#[AsMessageHandler]
final class GenerateMarketplacePLHandler
{
    public function __construct(
        private readonly GeneratePLFromMarketplaceAction $action,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateMarketplacePLMessage $message): void
    {
        $this->logger->info('Generating marketplace P&L document', [
            'companyId' => $message->companyId,
            'marketplace' => $message->marketplace,
            'stream' => $message->stream,
            'period' => $message->periodFrom . ' – ' . $message->periodTo,
        ]);

        try {
            $command = new GeneratePLCommand(
                companyId: $message->companyId,
                marketplace: $message->marketplace,
                stream: $message->stream,
                periodFrom: $message->periodFrom,
                periodTo: $message->periodTo,
                actorUserId: $message->actorUserId,
            );

            $documentId = ($this->action)($command);

            if ($documentId) {
                $this->logger->info('P&L document created', [
                    'documentId' => $documentId,
                    'companyId' => $message->companyId,
                ]);
            } else {
                $this->logger->info('No data to process for P&L', [
                    'companyId' => $message->companyId,
                    'marketplace' => $message->marketplace,
                    'stream' => $message->stream,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate P&L document', [
                'companyId' => $message->companyId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Rethrow для retry механизма Messenger
        }
    }
}
