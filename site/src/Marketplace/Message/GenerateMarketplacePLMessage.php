<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Асинхронное сообщение для генерации ОПиУ через Worker.
 *
 * Все поля scalar — безопасно для Redis Messenger.
 *
 * Использование:
 *   $this->messageBus->dispatch(new GenerateMarketplacePLMessage(...));
 */
final class GenerateMarketplacePLMessage
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $marketplace,
        public readonly string $stream,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $actorUserId,
    ) {
    }
}
