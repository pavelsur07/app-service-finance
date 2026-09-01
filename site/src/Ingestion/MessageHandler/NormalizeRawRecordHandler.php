<?php

declare(strict_types=1);

namespace App\Ingestion\MessageHandler;

use App\Ingestion\Application\Action\NormalizeOrderRawRecordAction;
use App\Ingestion\Application\Action\NormalizeRawRecordAction;
use App\Ingestion\Application\Command\NormalizeRawRecordCommand;
use App\Ingestion\Domain\Service\OrderMapperRegistry;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NormalizeRawRecordHandler
{
    public function __construct(
        private NormalizeRawRecordAction $action,
        private NormalizeOrderRawRecordAction $orderAction,
        private OrderMapperRegistry $orderMapperRegistry,
        private IngestRawRecordRepository $rawRecordRepository,
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
            // Маршрутизация по наличию маппера заказов, а не по списку
            // resourceType в условии: новый заказный ресурс регистрирует себя
            // сам через тег, и копий предиката не возникает.
            //
            // Ветка здесь, а не внутри финансового действия: так каждое из них
            // читает сырьё ровно один раз и остаётся односмысленным, а горячий
            // финансовый путь не растёт ради заказов.
            $rawRecord = $this->rawRecordRepository->findByIdAndCompany($message->rawRecordId, $message->companyId);
            $isOrderResource = null !== $rawRecord
                && $this->orderMapperRegistry->has($rawRecord->getSource(), $rawRecord->getResourceType());

            if ($isOrderResource) {
                ($this->orderAction)(new NormalizeRawRecordCommand($message->rawRecordId, $message->companyId));

                return;
            }

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
