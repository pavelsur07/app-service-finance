<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\RunPipelineAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineTrigger;
use App\Marketplace\Exception\PipelineAlreadyRunningException;
use App\Marketplace\Message\PipelineCompletedEvent;
use App\Marketplace\Message\TriggerProcessingPipelineMessage;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class TriggerProcessingPipelineHandler
{
    public function __construct(
        private readonly RunPipelineAction $runPipelineAction,
        #[Target('event.bus')] private readonly MessageBusInterface $eventBus,
    ) {}

    public function __invoke(TriggerProcessingPipelineMessage $message): void
    {
        $marketplace = MarketplaceType::from($message->marketplace);
        $triggeredBy = PipelineTrigger::from($message->triggeredBy);

        try {
            $run = ($this->runPipelineAction)(
                $message->companyId,
                $marketplace,
                $triggeredBy,
            );

            $this->eventBus->dispatch(new PipelineCompletedEvent(
                companyId:    $message->companyId,
                marketplace:  $message->marketplace,
                status:       $run->getStatus()->value,
                failedStep:   $run->getFailedStep()?->value,
                salesCount:   $run->getSalesCount(),
                returnsCount: $run->getReturnsCount(),
                costsCount:   $run->getCostsCount(),
            ));
        } catch (PipelineAlreadyRunningException) {
            return;
        }
    }
}
