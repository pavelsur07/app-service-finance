<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/** Запуск ИИ по компании из журнала `ai_run`. */
final readonly class AiRunView
{
    public function __construct(
        public \DateTimeImmutable $ranAt,
        public int $requested,
        public int $tasks,
        public int $redispatched,
        public int $expired,
        public ?string $skippedReason,
        public ?string $error,
    ) {
    }
}
