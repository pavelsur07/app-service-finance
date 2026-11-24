<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Cron: событие после commit подтверждения MCP-черновика могло не уйти — отправляем повторно. */
#[AsCommand(name: 'app:cash:recover-payment-intake', description: 'Повторно отправляет уведомления об изменении ДДС после подтверждения черновиков')]
final class RecoverPaymentIntakeCommand extends Command
{
    public function __construct(
        private readonly PaymentIntakeAction $action,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recovered = 0;
        $failed = [];
        $lastException = null;
        foreach ($this->action->pendingEventCompanyIds() as $companyId) {
            try {
                $recovered += $this->action->recoverEvents($companyId, true);
            } catch (\Throwable $exception) {
                $failed[] = $companyId;
                $lastException = $exception;
                if (!$this->em->isOpen()) {
                    break; // закрытый EM уронит все следующие компании; их подхватит следующий запуск cron
                }
            }
        }
        if ([] !== $failed) {
            $this->logger->error('Payment draft cash event recovery failed.', ['failedCompanies' => \count($failed), 'companyIds' => $failed, 'exception' => $lastException]);
        }
        $output->writeln(sprintf('Отправлено событий: %d, компаний со сбоем: %d', $recovered, \count($failed)));

        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
