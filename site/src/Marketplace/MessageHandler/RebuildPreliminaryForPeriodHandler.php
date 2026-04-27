<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Command\RebuildPreliminaryForPeriodCommand;
use App\Marketplace\Application\RebuildPreliminaryForPeriodAction;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RebuildPreliminaryForPeriodHandler
{
    public function __construct(
        private readonly RebuildPreliminaryForPeriodAction $action,
        private readonly LoggerInterface                   $logger,
    ) {
    }

    public function __invoke(RebuildPreliminaryForPeriodMessage $message): void
    {
        $this->logger->info('[PreliminaryRebuild] Handler started', [
            'company_id'  => $message->companyId,
            'marketplace' => $message->marketplace,
            'year'        => $message->year,
            'month'       => $message->month,
        ]);

        try {
            ($this->action)(new RebuildPreliminaryForPeriodCommand(
                companyId:   $message->companyId,
                marketplace: $message->marketplace,
                year:        $message->year,
                month:       $message->month,
                actorUserId: $message->actorUserId,
            ));

            $this->logger->info('[PreliminaryRebuild] Handler completed', [
                'company_id'  => $message->companyId,
                'marketplace' => $message->marketplace,
            ]);
        } catch (\DomainException $e) {
            // DomainException — данные не готовы, не ретраим.
            $this->logger->warning('[PreliminaryRebuild] Domain error — no retry', [
                'company_id'  => $message->companyId,
                'marketplace' => $message->marketplace,
                'error'       => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[PreliminaryRebuild] Handler failed', [
                'company_id'  => $message->companyId,
                'marketplace' => $message->marketplace,
                'error'       => $e->getMessage(),
            ]);

            throw $e; // Ретраим технические ошибки.
        }
    }
}
