<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * Ответ 202 на постановку: задача записана в БД сервиса. При повторе с тем же ключом —
 * прежний `taskId` и `duplicate=true`.
 */
final readonly class AiTaskAccepted
{
    public function __construct(
        public string $taskId,
        public string $status,
        public bool $duplicate,
    ) {
    }
}
