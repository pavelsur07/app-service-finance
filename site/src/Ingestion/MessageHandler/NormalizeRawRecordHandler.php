<?php

declare(strict_types=1);

namespace App\Ingestion\MessageHandler;

use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NormalizeRawRecordHandler
{
    public function __construct(
        private NormalizeRawRecordAction $action,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NormalizeRawRecordMessage $message): void
    {
        $this->logger->info('Ingestion raw normalization started.', [
            'companyId' => $message->companyId,
            'rawRecordId' => $message->rawRecordId,
        ]);

        try {
            ($this->action)(new NormalizeRawRecordCommand($message->rawRecordId, $message->companyId));
        } catch (\Throwable $exception) {
            $this->logger->error('Ingestion raw normalization failed.', [
                'companyId' => $message->companyId,
                'rawRecordId' => $message->rawRecordId,
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
