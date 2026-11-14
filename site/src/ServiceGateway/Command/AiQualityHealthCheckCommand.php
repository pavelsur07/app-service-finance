<?php

declare(strict_types=1);

namespace App\ServiceGateway\Command;

use App\ServiceGateway\Application\Action\AiQualityAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ai-service:quality:health-check', description: 'PaymentIntake nightly quality: health-check')]
final class AiQualityHealthCheckCommand extends Command
{
    public function __construct(private readonly AiQualityAction $quality)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $state = $this->quality->health();
        $output->writeln('payment intake quality: '.$state);

        return \in_array($state, ['disabled', 'waiting', 'passed'], true) ? self::SUCCESS : self::FAILURE;
    }
}
