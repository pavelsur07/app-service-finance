<?php

declare(strict_types=1);

namespace App\Telegram\MessageHandler;

use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeDraftView;
use App\Cash\Facade\CashFacade;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\ServiceGateway\Facade\AiTaskResultFacadeInterface;
use App\Telegram\Application\PaymentIntakePresenter;
use App\Telegram\Application\QueuePaymentIntakePollAction;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\PaymentIntakeSession;
use App\Telegram\Message\RefreshPaymentIntakeMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class RefreshPaymentIntakeMessageHandler
{
    private const MAX_ATTEMPTS = 15;

    public function __construct(private EntityManagerInterface $em, private CashFacade $cash, private CompanyFacade $companies, private FinancialResponsibilityCenterFacade $centers, private AiTaskResultFacadeInterface $results, private PaymentIntakePresenter $presenter, private HttpClientInterface $http, private QueuePaymentIntakePollAction $polls, private LoggerInterface $telegramLogger, private string $telegramApiBaseUrl = 'https://api.telegram.org')
    {
    }

    public function __invoke(RefreshPaymentIntakeMessage $message): void
    {
        $this->telegramLogger->info('Telegram intake refresh started', ['companyId' => $message->companyId, 'sessionId' => $message->sessionId, 'attempt' => $message->attempt]);
        $session = $this->em->getRepository(PaymentIntakeSession::class)->findOneBy(['id' => $message->sessionId, 'companyId' => $message->companyId]);
        if (!$session instanceof PaymentIntakeSession || !$session->pollingMatches($message->generation)) {
            return;
        }
        $claimedLease = $this->em->getConnection()->fetchOne("UPDATE telegram_payment_intake_session SET lease_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds' WHERE id = :id AND company_id = :company AND poll_generation = :generation AND last_sequence < :sequence AND (lease_until IS NULL OR lease_until < CURRENT_TIMESTAMP) RETURNING lease_until", ['id' => $message->sessionId, 'company' => $message->companyId, 'generation' => $message->generation, 'sequence' => $message->sequence]);
        if (false === $claimedLease) {
            $progress = $this->em->getConnection()->fetchAssociative('SELECT poll_generation, last_sequence FROM telegram_payment_intake_session WHERE id = :id AND company_id = :company', ['id' => $message->sessionId, 'company' => $message->companyId]);
            if (false !== $progress && $progress['poll_generation'] === $message->generation && (int) $progress['last_sequence'] < $message->sequence) {
                // A live lease can belong to the predecessor which just published this step.
                throw new RecoverableMessageHandlingException('Telegram intake delivery lease is busy.', retryDelay: 2000);
            }
            // A previous delivery may have committed its successor then failed to enqueue it.
            ($this->polls)($session);

            return;
        }
        try {
            $this->refresh($message, $session, (string) $claimedLease);
        } finally {
            $this->em->getConnection()->executeStatement('UPDATE telegram_payment_intake_session SET lease_until = NULL WHERE id = :id AND company_id = :company AND lease_until = :lease', ['id' => $message->sessionId, 'company' => $message->companyId, 'lease' => $claimedLease]);
        }
    }

    private function refresh(RefreshPaymentIntakeMessage $message, PaymentIntakeSession $session, string $claimedLease): void
    {
        $binding = $session->getBinding();
        $actor = $binding->getActorUserId();
        if (ClientBinding::STATUS_ACTIVE !== $binding->getStatus() || null === $actor || !$binding->getBot()->isActive() || !$this->companies->financialAccess($message->companyId, $actor)['write']) {
            $this->telegramLogger->info('Telegram intake delivery denied', ['sessionId' => $message->sessionId]);

            return;
        }
        $this->cash->recoverPaymentIntakeDraft($message->companyId, $actor, $session->getDraftId());
        $draft = $this->cash->getPaymentIntakeDraft($message->companyId, $actor, $session->getDraftId());
        if (in_array($draft->status, ['pending', 'queued'], true) && null !== $draft->taskId) {
            try {
                $this->results->refreshTask($message->companyId, $draft->taskId);
            } catch (\Throwable $exception) {
                // Safe IDs only: transport exception messages can contain token-bearing URLs.
                $this->telegramLogger->warning('Telegram intake quick pull failed', ['sessionId' => $message->sessionId, 'exceptionClass' => $exception::class]);
            }
            // The importer can clear/refresh entities; always re-read the current draft.
            $draft = $this->cash->getPaymentIntakeDraft($message->companyId, $actor, $session->getDraftId());
        }
        $pending = in_array($draft->status, ['pending', 'queued'], true);
        $timedOut = $pending && $message->attempt >= self::MAX_ATTEMPTS;
        $state = $draft->revision.':'.$draft->status.($timedOut ? ':waiting' : '');
        $moreCards = false;
        $deliveryRequestToken = $session->getDeliveryRequestToken();
        $sentMessageId = null;
        $rendered = $session->needsDelivery($state);
        if ($rendered) {
            $names = [];
            foreach ($this->companies->listProjectDirections($message->companyId) as $project) {
                $names['project_id'][$project->id] = $project->name;
            }
            foreach ($this->cash->getCashflowCategoryTree($message->companyId) as $category) {
                $names['category_id'][$category->id] = $category->name;
            }
            foreach ($this->centers->getActiveChoices($message->companyId) as $center) {
                $names['cfo_id'][$center->id] = $center->name;
            }
            $accountName = 'Недоступная касса';
            foreach ($this->cash->listMoneyAccounts($message->companyId, false) as $account) {
                if ($account->id === $draft->moneyAccountId) {
                    $accountName = $account->name;
                    break;
                }
            }
            $messages = $this->presenter->present($draft, $session->getId(), [], $accountName, $names);
            if ($timedOut) {
                $messages[0]['text'] = 'Разбор ещё выполняется. Платежи не созданы. Нажмите «Обновить» позже; исходный черновик сохранён.';
            }
            $cursor = $session->deliveryCursor($state);
            if (isset($messages[$cursor])) {
                $payload = $messages[$cursor];
                try {
                    $response = $this->http->request('POST', sprintf('%s/bot%s/sendMessage', rtrim($this->telegramApiBaseUrl, '/'), $binding->getBot()->getToken()), ['json' => ['chat_id' => $session->getChatId()] + $payload, 'timeout' => 5, 'max_duration' => 8]);
                    $status = $response->getStatusCode();
                    $body = $response->toArray(false);
                } catch (\Throwable $exception) {
                    throw new \RuntimeException('Telegram intake delivery transport failed: '.$exception::class);
                }
                if (200 !== $status || true !== ($body['ok'] ?? false)) {
                    throw new \RuntimeException('Telegram intake delivery failed (HTTP '.$status.').');
                }
                $sentMessageId = isset($body['result']['message_id']) ? (string) $body['result']['message_id'] : null;
            }
            $moreCards = $cursor + 1 < count($messages);
        }
        $this->finalizeDelivery($message, $session, $draft, $actor, $claimedLease, $deliveryRequestToken, $state, $rendered, $sentMessageId, $moreCards, $pending, $timedOut);
        ($this->polls)($session);
        $this->telegramLogger->info('Telegram intake refresh finished', ['sessionId' => $message->sessionId, 'status' => $draft->status, 'attempt' => $message->attempt]);
    }

    private function finalizeDelivery(RefreshPaymentIntakeMessage $message, PaymentIntakeSession $session, PaymentIntakeDraftView $renderedDraft, string $actor, string $claimedLease, ?string $requestToken, string $state, bool $rendered, ?string $sentMessageId, bool $moreCards, bool $pending, bool $timedOut): void
    {
        // HTTP stays outside the transaction. Finalization refreshes under a row lock so an
        // older card cannot clear a webhook's newer request or overwrite its delivery cursor.
        $this->em->wrapInTransaction(function () use ($message, $session, $renderedDraft, $actor, $claimedLease, $requestToken, $state, $rendered, $sentMessageId, $moreCards, $pending, $timedOut): void {
            $progress = $this->em->getConnection()->fetchAssociative('SELECT lease_until, last_sequence FROM telegram_payment_intake_session WHERE id = :id AND company_id = :company FOR UPDATE', ['id' => $message->sessionId, 'company' => $message->companyId]);
            if (false === $progress) {
                return;
            }
            $this->em->refresh($session);
            $session->rememberReplyMessage($sentMessageId);
            if (!$session->pollingMatches($message->generation) || $progress['lease_until'] !== $claimedLease || (int) $progress['last_sequence'] >= $message->sequence) {
                return;
            }
            $current = $this->cash->getPaymentIntakeDraft($message->companyId, $actor, $session->getDraftId());
            if ($requestToken !== $session->getDeliveryRequestToken() || $current->revision !== $renderedDraft->revision || $current->status !== $renderedDraft->status) {
                if ($requestToken === $session->getDeliveryRequestToken()) {
                    $session->requireDelivery();
                }
                $session->recordSequence($message->sequence);
                $session->preparePoll(0, $message->sequence + 1, 1100);

                return;
            }
            if ($rendered) {
                $session->recordDeliveredPart($state, $sentMessageId);
                if (!$moreCards) {
                    $session->markDelivered($state, []);
                }
            }
            $session->recordSequence($message->sequence);
            if ($moreCards || ($pending && !$timedOut)) {
                $session->preparePoll($moreCards ? $message->attempt : $message->attempt + 1, $message->sequence + 1, $moreCards ? 1100 : 2000);
            } else {
                $session->finishPolling();
            }
        });
    }
}
