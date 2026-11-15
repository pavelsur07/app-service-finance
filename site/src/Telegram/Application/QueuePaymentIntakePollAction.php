<?php

declare(strict_types=1);

namespace App\Telegram\Application;

use App\Telegram\Entity\PaymentIntakeSession;
use App\Telegram\Message\RefreshPaymentIntakeMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/** Publishes a persisted polling intent; a crash before acknowledgement safely republishes it. */
final readonly class QueuePaymentIntakePollAction
{
    public function __construct(private EntityManagerInterface $em, private MessageBusInterface $bus)
    {
    }

    public function __invoke(PaymentIntakeSession $session): bool
    {
        $this->em->refresh($session);
        $intent = $session->pendingPoll();
        $generation = $session->getPollGeneration();
        if (null === $intent || null === $generation) {
            return false;
        }
        $message = new RefreshPaymentIntakeMessage($session->getCompanyId(), $session->getId(), $intent['attempt'], $generation, $intent['sequence']);
        $this->bus->dispatch($message, $intent['delay'] > 0 ? [new DelayStamp($intent['delay'])] : []);
        // The receiver may already have prepared its successor. Never acknowledge that newer intent.
        $this->em->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET pending_poll = NULL WHERE id = :id AND company_id = :company AND poll_generation = :generation AND pending_poll->>'sequence' = :sequence", ['id' => $session->getId(), 'company' => $session->getCompanyId(), 'generation' => $generation, 'sequence' => (string) $intent['sequence']]);
        $this->em->refresh($session);

        return true;
    }
}
