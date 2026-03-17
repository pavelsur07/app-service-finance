<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Message\CloseMonthStageMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CloseMonthStageHandler
{
    public function __construct(
        private readonly CloseMonthStageAction $action,
        private readonly LoggerInterface       $logger,
    ) {
    }

    public function __invoke(CloseMonthStageMessage $message): void
    {
        $this->logger->info('[MonthClose] Handler started', [
            'company_id'  => $message->companyId,
            'marketplace' => $message->marketplace,
            'year'        => $message->year,
            'month'       => $message->month,
            'stage'       => $message->stage,
        ]);

        try {
            $command = new CloseMonthStageCommand(
                companyId:   $message->companyId,
                marketplace: $message->marketplace,
                year:        $message->year,
                month:       $message->month,
                stage:       $message->stage,
                actorUserId: $message->actorUserId,
            );

            $result = ($this->action)($command);

            $this->logger->info('[MonthClose] Handler completed', [
                'month_close_id' => $result['monthCloseId'],
                'pl_documents'   => count($result['plDocumentIds']),
            ]);
        } catch (\DomainException $e) {
            // DomainException — не ретраим, данные не готовы
            $this->logger->error('[MonthClose] Domain error — no retry', [
                'company_id' => $message->companyId,
                'stage'      => $message->stage,
                'error'      => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MonthClose] Handler failed', [
                'company_id' => $message->companyId,
                'stage'      => $message->stage,
                'error'      => $e->getMessage(),
            ]);

            throw $e; // Ретраим для технических ошибок
        }
    }
}
