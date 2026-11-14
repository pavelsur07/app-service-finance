<?php

declare(strict_types=1);

namespace App\Telegram\Command;

use App\Cash\Facade\CashFacade;
use App\Company\Facade\CompanyFacade;
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
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:telegram:recover-payment-intake', description: 'Recover pending Telegram draft notifications for one company; never confirms payments')]
final class RecoverPaymentIntakeDeliveryCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly CashFacade $cash, private readonly CompanyFacade $companies, private readonly QueuePaymentIntakePollAction $polls, private readonly LoggerInterface $telegramLogger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('company-id', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getArgument('company-id');
        if (!Uuid::isValid($companyId)) {
            $output->writeln('Invalid company UUID.');

            return Command::INVALID;
        }
        // Trusted console entry: recover Cash intents even before a Telegram session exists.
        $this->cash->recoverPaymentIntake($companyId);
        $queued = 0;
        $afterId = null;
        do {
            $params = ['company' => $companyId];
            $after = '';
            if (null !== $afterId) {
                $after = ' AND id > :after';
                $params['after'] = $afterId;
            }
            $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM telegram_payment_intake_session WHERE company_id = :company AND (delivery_pending = true OR pending_poll IS NOT NULL OR poll_generation IS NOT NULL)'.$after.' ORDER BY id LIMIT 100', $params);
            foreach ($ids as $id) {
                $afterId = (string) $id;
                try {
                    $session = $this->em->getRepository(PaymentIntakeSession::class)->findOneBy(['companyId' => $companyId, 'id' => $afterId]);
                    if (!$session instanceof PaymentIntakeSession) {
                        continue;
                    }
                    $binding = $session->getBinding();
                    $actor = $binding->getActorUserId();
                    if (ClientBinding::STATUS_ACTIVE !== $binding->getStatus() || null === $actor || !$this->companies->financialAccess($companyId, $actor)['write']) {
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
                }
            }
            // Every candidate has committed before clearing the batch's managed entities.
            $this->em->clear();
        } while (100 === count($ids));
        $output->writeln(sprintf('Queued notifications: %d', $queued));

        return Command::SUCCESS;
    }
}
