<?php

declare(strict_types=1);

namespace App\Cash\Application\MessageHandler;

use App\Cash\Application\Command\ScoreCompanyCounterpartiesCommand;
use App\Cash\Application\Message\ScoreCompanyCounterpartiesMessage;
use App\Cash\Application\ScoreCompanyCounterpartiesAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ScoreCompanyCounterpartiesHandler
{
    public function __construct(
        private readonly ScoreCompanyCounterpartiesAction $action
    ) {}

    public function __invoke(ScoreCompanyCounterpartiesMessage $message): void
    {
        // Преобразуем Message (асинхронный транспорт) в Command (CQRS бизнес-действие)
        $command = new ScoreCompanyCounterpartiesCommand($message->companyId);

        ($this->action)($command);
    }
}
