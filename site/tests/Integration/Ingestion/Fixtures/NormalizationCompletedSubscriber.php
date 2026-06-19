<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Domain\Event\NormalizationCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class NormalizationCompletedSubscriber implements EventSubscriberInterface
{
    public function __construct(private NormalizationCompletedRecorder $recorder)
    {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            NormalizationCompletedEvent::class => 'onCompleted',
        ];
    }

    public function onCompleted(NormalizationCompletedEvent $event): void
    {
        $this->recorder->record($event);
    }
}
