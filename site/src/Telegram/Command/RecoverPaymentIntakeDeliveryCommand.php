<?php

declare(strict_types=1);

namespace App\Telegram\Command;

use App\Cash\Facade\CashFacade;
use App\Company\Facade\CompanyFacade;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Telegram\Application\QueuePaymentIntakePollAction;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\PaymentIntakeSession;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:telegram:recover-payment-intake', description: 'Recover pending Telegram draft notifications for one or all companies; never confirms payments')]
final class RecoverPaymentIntakeDeliveryCommand extends Command
{
    private const CANDIDATES = '(delivery_pending = true OR pending_poll IS NOT NULL OR poll_generation IS NOT NULL)';

    public function __construct(private readonly EntityManagerInterface $em, private readonly CashFacade $cash, private readonly CompanyFacade $companies, private readonly QueuePaymentIntakePollAction $polls, private readonly LoggerInterface $telegramLogger, private readonly AiTaskFacadeInterface $ai)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('company-id', InputArgument::OPTIONAL);
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Recover connected companies and pending Telegram deliveries.');
        $this->addOption('skip-cash-recovery', null, InputOption::VALUE_NONE, 'Recover Telegram delivery only; Cash recovery is run separately.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = $input->getArgument('company-id');
        $all = (bool) $input->getOption('all');
        if (($all && null !== $companyId) || (!$all && (!is_string($companyId) || !Uuid::isValid($companyId)))) {
            $output->writeln('Specify either a valid company UUID or --all.');

            return Command::INVALID;
        }
        $companyIds = $all ? $this->candidateCompanyIds() : [strtolower((string) $companyId)];
        $queued = 0;
        $failed = false;
        foreach ($companyIds as $id) {
            // Recheck the grant immediately before Cash recovery; delivery needs no AI grant.
            try {
                if (!$input->getOption('skip-cash-recovery')) {
                    $this->cash->recoverPaymentIntakeEvents($id);
                    if ($this->ai->isEnabledForCompany($id)) {
                        $this->cash->recoverPaymentIntake($id);
                    }
                }
            } catch (\Throwable $exception) {
                $failed = true;
                $this->logFailure($id, 'cash', $exception);
            }
            if (!$this->em->isOpen()) {
                return Command::FAILURE;
            }
            try {
                [$count, $deliveryFailed] = $this->recoverDelivery($id);
                $queued += $count;
                $failed = $failed || $deliveryFailed;
            } catch (\Throwable $exception) {
                $failed = true;
                $this->logFailure($id, 'telegram', $exception);
            }
            if (!$this->em->isOpen()) {
                return Command::FAILURE;
            }
        }
        $output->writeln(sprintf('Queued notifications: %d', $queued));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> */
    private function candidateCompanyIds(): array
    {
        // Technical enumeration returns IDs only; every subsequent read is company-scoped.
        $pending = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT company_id FROM telegram_payment_intake_session WHERE '.self::CANDIDATES);
        $ids = array_values(array_unique([...$this->ai->connectedCompanyIds(), ...$this->cash->pendingPaymentIntakeEventCompanyIds(), ...array_map('strval', $pending)]));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @return array{int, bool} */
    private function recoverDelivery(string $companyId): array
    {
        $queued = 0;
        $failed = false;
        $afterId = null;
        do {
            $params = ['company' => $companyId];
            $after = '';
            if (null !== $afterId) {
                $after = ' AND id > :after';
                $params['after'] = $afterId;
            }
            $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM telegram_payment_intake_session WHERE company_id = :company AND '.self::CANDIDATES.$after.' ORDER BY id LIMIT 100', $params);
            foreach ($ids as $id) {
                $afterId = (string) $id;
                try {
                    $session = $this->em->getRepository(PaymentIntakeSession::class)->findOneBy(['companyId' => $companyId, 'id' => $afterId]);
                    if (!$session instanceof PaymentIntakeSession) {
                        continue;
                    }
                    $binding = $session->getBinding();
                    $actor = $binding->getActorUserId();
                    if (ClientBinding::STATUS_ACTIVE !== $binding->getStatus() || null === $actor || !$binding->getBot()->isActive() || !$this->companies->financialAccess($companyId, $actor)['write']) {
                        continue;
                    }
                    $draft = $this->cash->getPaymentIntakeDraft($companyId, $actor, $session->getDraftId());
                    if (!$session->needsDelivery($draft->revision.':'.$draft->status) && null === $session->pendingPoll() && !in_array($draft->status, ['pending', 'queued'], true)) {
                        continue;
                    }
                    if (null === $session->pendingPoll()) {
                        $session->schedulePolling();
                    }
                    $this->em->flush();
                    if (($this->polls)($session)) {
                        ++$queued;
                    }
                } catch (\DomainException $exception) {
                    $this->telegramLogger->info('Telegram intake recovery skipped inaccessible draft', ['companyId' => $companyId, 'sessionId' => $afterId, 'exceptionClass' => $exception::class]);
                } catch (\Throwable $exception) {
                    $failed = true;
                    $this->logFailure($companyId, 'telegram', $exception, $afterId);
                    if (!$this->em->isOpen()) {
                        return [$queued, true];
                    }
                }
            }
            // Every candidate has committed before clearing the batch's managed entities.
            $this->em->clear();
        } while (100 === count($ids));

        return [$queued, $failed];
    }

    private function logFailure(string $companyId, string $channel, \Throwable $exception, ?string $sessionId = null): void
    {
        $this->telegramLogger->error('Telegram intake recovery failed', ['companyId' => $companyId, 'channel' => $channel, 'sessionId' => $sessionId, 'exceptionClass' => $exception::class]);
    }
}
