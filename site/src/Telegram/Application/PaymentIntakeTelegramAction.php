<?php

declare(strict_types=1);

namespace App\Telegram\Application;

use App\Cash\Application\DTO\PaymentIntake\AmendPaymentIntakeDraftCommand;
use App\Cash\Application\DTO\PaymentIntake\ConfirmPaymentIntakeDraftCommand;
use App\Cash\Application\DTO\PaymentIntake\RequestPaymentIntakeCommand;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Facade\CashFacade;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\PaymentIntakeSession;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use Doctrine\ORM\EntityManagerInterface;

/** Telegram identity/context adapter. Cash alone validates and writes payments. */
final readonly class PaymentIntakeTelegramAction
{
    public function __construct(private EntityManagerInterface $em, private CashFacade $cash, private CompanyFacade $companies, private FinancialResponsibilityCenterFacade $centers, private AiTaskFacadeInterface $ai, private QueuePaymentIntakePollAction $polls, private bool $telegramPaymentIntakeEnabled = false)
    {
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>|null null means legacy path is permitted
     */
    public function message(TelegramBot $bot, array $message, string $text, bool $edited = false, int $updateId = 0): ?array
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $messageId = (string) ($message['message_id'] ?? '');
        // Existence-only routing metadata is safe before actor lookup; no draft content is read.
        $knownSource = $this->cash->hasPaymentIntakeSource((string) $bot->getId(), $chatId, $messageId);
        $user = $this->em->getRepository(TelegramUser::class)->findOneBy(['tgUserId' => (string) ($message['from']['id'] ?? '')]);
        if (!$user instanceof TelegramUser) {
            return $knownSource ? ['text' => 'Сообщение уже принято в разбор платежей. Повтор не создаёт операцию.'] : null;
        }
        // Source identity is resolved before selected company. No source data is returned before authorization.
        $session = $this->em->getRepository(PaymentIntakeSession::class)->findOneBy(['botId' => $bot->getId(), 'chatId' => $chatId, 'messageId' => $messageId]);
        $replyId = (string) ($message['reply_to_message']['message_id'] ?? '');
        $isReply = false;
        if (!$session instanceof PaymentIntakeSession && '' !== $replyId) {
            $matches = $this->em->getConnection()->fetchAllAssociative('SELECT s.id, s.company_id, b.actor_user_id, b.status FROM telegram_payment_intake_session s INNER JOIN client_bindings b ON b.id = s.binding_id AND b.company_id = s.company_id WHERE s.bot_id = :bot AND s.chat_id = :chat AND b.bot_id = :bot AND b.telegram_user_id = :user AND CAST(s.reply_message_ids AS JSONB) @> CAST(:reply AS JSONB) LIMIT 2', ['bot' => $bot->getId(), 'chat' => $chatId, 'user' => $user->getId(), 'reply' => json_encode([$replyId], \JSON_THROW_ON_ERROR)]);
            if ([] !== $matches) {
                if (1 !== count($matches) || ClientBinding::STATUS_ACTIVE !== $matches[0]['status'] || null === $matches[0]['actor_user_id'] || !$this->companies->financialAccess($matches[0]['company_id'], $matches[0]['actor_user_id'])['write']) {
                    return ['text' => 'Черновик недоступен или ответ неоднозначен. Откройте актуальную карточку.'];
                }
                $session = $this->em->getRepository(PaymentIntakeSession::class)->findOneBy(['id' => $matches[0]['id'], 'companyId' => $matches[0]['company_id']]);
                $isReply = $session instanceof PaymentIntakeSession;
            }
        }
        $bindings = $this->em->getRepository(ClientBinding::class)->findBy(['telegramUser' => $user, 'bot' => $bot, 'status' => ClientBinding::STATUS_ACTIVE]);
        if (!$session instanceof PaymentIntakeSession && !$isReply) {
            // Recover a Cash-committed source after a crash before Telegram session creation.
            foreach ($bindings as $candidate) {
                $candidateActor = $candidate->getActorUserId();
                if (null === $candidateActor) {
                    continue;
                }
                $sourceDraft = $this->cash->findPaymentIntakeDraftBySource((string) $bot->getId(), $chatId, $messageId, $candidateActor);
                $knownSource = $knownSource || null !== $sourceDraft;
                if (null !== $sourceDraft && $sourceDraft->companyId === $candidate->getCompany()->getId()) {
                    $session = new PaymentIntakeSession($candidate, $chatId, $messageId, $sourceDraft->id);
                    $this->em->persist($session);
                    $this->em->flush();
                    break;
                }
            }
            if ($knownSource && !$session instanceof PaymentIntakeSession) {
                return ['text' => 'Исходный черновик недоступен. Повтор не создаёт операцию в другой компании.'];
            }
        }
        $binding = $session instanceof PaymentIntakeSession ? $session->getBinding() : null;
        if (null === $binding) {
            foreach ($bindings as $candidate) {
                if ($candidate->isSelected() || 1 === count($bindings)) {
                    $binding = $candidate;
                    break;
                }
            }
        }
        if (!$this->telegramPaymentIntakeEnabled) {
            if (!$session instanceof PaymentIntakeSession && (null === $binding || !$binding->isPaymentIntakeSelected())) {
                return null;
            }

            return ['text' => 'Новый разбор платежей пока не включён. Платежи не созданы.'];
        }
        if (null === $binding) {
            foreach ($bindings as $candidate) {
                if ([] !== $this->ai->grantedCompanyIds([(string) $candidate->getCompany()->getId()])) {
                    return ['text' => 'Выберите компанию и кассу: /set_cash'];
                }
            }

            return null;
        }
        $companyId = (string) $binding->getCompany()->getId();
        // A configured company cannot fall through when the service/global switch is down.
        if (!$session instanceof PaymentIntakeSession && !$binding->isPaymentIntakeSelected() && [] === $this->ai->grantedCompanyIds([$companyId])) {
            return null;
        }
        if ($binding->getTelegramUser()->getId() !== $user->getId() || $binding->getBot()->getId() !== $bot->getId() || ClientBinding::STATUS_ACTIVE !== $binding->getStatus()) {
            return ['text' => 'Привязка недействительна. Выполните /start по новой ссылке.'];
        }
        if (null === $binding->getActorUserId()) {
            return ['text' => 'Для разбора платежей обновите привязку через новую ссылку из личного кабинета.'];
        }
        $actor = $binding->getActorUserId();
        if (!$this->companies->financialAccess($companyId, $actor)['write']) {
            return ['text' => 'Нет права записи платежей в выбранной компании.'];
        }
        if (($message['chat']['type'] ?? 'private') !== 'private' || $chatId !== (string) $user->getTgUserId()) {
            return ['text' => 'Разбор платежей доступен только в личном чате с ботом.'];
        }
        if ($session instanceof PaymentIntakeSession) {
            $this->cash->recoverPaymentIntakeDraft($companyId, $actor, $session->getDraftId());
        }
        if (!$binding->isPaymentIntakeSelected()) {
            $binding->selectPaymentIntake();
            $this->em->flush();
        }
        if ('' === $messageId || '' === $chatId) {
            return ['text' => 'У сообщения нет идентификатора. Отправьте его заново.'];
        }
        if (mb_strlen($text) > 16000) {
            return ['text' => 'Сообщение слишком длинное (лимит 16000 символов). Разделите его на несколько сообщений.'];
        }
        if ($session instanceof PaymentIntakeSession) {
            $draft = $this->cash->getPaymentIntakeDraft($companyId, $actor, $session->getDraftId());
            if ($edited || $isReply) {
                if (in_array($draft->status, ['confirmed', 'cancelled'], true)) {
                    return ['text' => 'Этот черновик уже завершён. Изменение сообщения не меняет сохранённые платежи; создайте отдельный запрос.'];
                }
                if ($isReply) {
                    return $this->correct($session, $draft->revision, $text, $messageId);
                }
                $editDate = (int) ($message['edit_date'] ?? 0);
                $previous = explode(':', $session->getSourceRevision());
                if ('edit' === $previous[0] && ($editDate < (int) $previous[1] || ($editDate === (int) $previous[1] && $updateId <= (int) $previous[2]))) {
                    return ['text' => 'Более новая версия сообщения уже принята.'];
                }
                $sourceRevision = 'edit:'.$editDate.':'.$updateId.':'.substr(hash('sha256', $text), 0, 20);
                if ($sourceRevision !== $session->getSourceRevision()) {
                    if (!$this->ai->isEnabledForCompany($companyId)) {
                        return ['text' => 'ИИ-разбор сейчас недоступен. Платежи не созданы. Попробуйте позже.'];
                    }
                    $this->markDeliveryPending($session);
                    $this->cash->amendPaymentIntakeDraft(new AmendPaymentIntakeDraftCommand($companyId, $actor, $draft->id, $draft->revision, text: $text, sourceRevision: $sourceRevision));
                    // Session ordering is a hint; update it monotonically even if this webhook read a stale entity.
                    $this->em->getConnection()->executeStatement("UPDATE telegram_payment_intake_session SET source_revision = :revision WHERE id = :id AND company_id = :company AND (source_revision NOT LIKE 'edit:%' OR CAST(NULLIF(split_part(source_revision, ':', 2), '') AS BIGINT) < :date OR (CAST(NULLIF(split_part(source_revision, ':', 2), '') AS BIGINT) = :date AND CAST(NULLIF(split_part(source_revision, ':', 3), '') AS BIGINT) < :update))", ['revision' => $sourceRevision, 'id' => $session->getId(), 'company' => $companyId, 'date' => $editDate, 'update' => $updateId]);
                    $this->em->refresh($session);
                    $session->requireDelivery();
                    $this->em->flush();
                }
            }
        } else {
            if ($edited) {
                return ['text' => 'Исходный черновик не найден. Отправьте новое сообщение; сохранённые операции не изменены.'];
            }
            if (preg_match('/^(измени|исправ|у второго|у первого|всё на|все на)/iu', trim($text))) {
                return ['text' => 'Укажите черновик: ответьте на его карточку и назовите строку и поле для изменения.'];
            }
            $account = $binding->getMoneyAccount();
            if (null === $account) {
                return ['text' => 'Сначала выберите кассу: /set_cash'];
            }
            if (!$this->ai->isEnabledForCompany($companyId)) {
                return ['text' => 'ИИ-разбор сейчас недоступен. Платежи не созданы. Попробуйте позже.'];
            }
            $draft = $this->cash->requestPaymentIntake(new RequestPaymentIntakeCommand($companyId, $actor, (string) $account->getId(), (string) $bot->getId(), $chatId, $messageId, $text, new \DateTimeImmutable('@'.(int) ($message['date'] ?? time()))));
            $session = new PaymentIntakeSession($binding, $chatId, $messageId, $draft->id);
            $this->em->persist($session);
            $this->em->flush();
        }
        $this->dispatch($session);

        return ['text' => 'Сообщение принято. Разбираю платежи; запись произойдёт только после подтверждения.'];
    }

    /**
     * @param array<string, mixed> $callback
     *
     * @return array<string, mixed>
     */
    public function callback(TelegramBot $bot, array $callback): array
    {
        if (!$this->telegramPaymentIntakeEnabled) {
            return ['text' => 'Новый разбор платежей пока не включён. Платежи не изменены.'];
        }
        $parts = explode(':', (string) ($callback['data'] ?? ''), 4);
        if (4 !== count($parts) || !ctype_digit($parts[2])) {
            return ['text' => 'Некорректная кнопка.'];
        }
        try {
            $sessionId = PaymentIntakePresenter::decodeId($parts[1]);
        } catch (\InvalidArgumentException) {
            return ['text' => 'Некорректная кнопка.'];
        }
        $session = $this->em->getRepository(PaymentIntakeSession::class)->findOneBy(['id' => $sessionId, 'botId' => $bot->getId(), 'chatId' => (string) ($callback['message']['chat']['id'] ?? '')]);
        if (!$session instanceof PaymentIntakeSession) {
            return ['text' => 'Черновик недоступен.'];
        }
        $binding = $session->getBinding();
        $companyId = $session->getCompanyId();
        $actor = $binding->getActorUserId();
        if ((string) ($callback['from']['id'] ?? '') !== $binding->getTelegramUser()->getTgUserId() || ClientBinding::STATUS_ACTIVE !== $binding->getStatus() || null === $actor || !$this->companies->financialAccess($companyId, $actor)['write']) {
            return ['text' => 'Черновик недоступен или право записи отозвано.'];
        }
        $this->cash->recoverPaymentIntakeDraft($companyId, $actor, $session->getDraftId());
        $draft = $this->cash->getPaymentIntakeDraft($companyId, $actor, $session->getDraftId());
        $revision = (int) $parts[2];
        $action = $parts[3];
        if ($draft->revision !== $revision && 'refresh' !== $action) {
            return ['text' => 'Карточка устарела. Нажмите «Обновить» в актуальной версии.'];
        }
        if ('refresh' === $action) {
            $session->requireDelivery();
        } elseif ('cancel' === $action) {
            $this->markDeliveryPending($session);
            $this->cash->cancelPaymentIntakeDraft($companyId, $actor, $draft->id, $revision);
            $session->requireDelivery();
        } elseif ('resolve' === $action) {
            if ([] === $draft->items) {
                return ['text' => 'Сначала добавьте конкретные платежи и их суммы.'];
            }
            $this->markDeliveryPending($session);
            $this->cash->amendPaymentIntakeDraft(new AmendPaymentIntakeDraftCommand($companyId, $actor, $draft->id, $revision, questions: []));
            $session->requireDelivery();
        } elseif ('confirm' === $action) {
            $ids = array_column(array_filter($draft->items, static fn (array $item): bool => !($item['excluded'] ?? false)), 'id');
            $this->markDeliveryPending($session);
            $this->cash->confirmPaymentIntakeDraft(new ConfirmPaymentIntakeDraftCommand($companyId, $actor, $draft->id, $revision, $ids));
            $session->requireDelivery();
        } elseif ('retry' === $action) {
            if (!$this->ai->isEnabledForCompany($companyId)) {
                return ['text' => 'ИИ-разбор сейчас недоступен. Платежи не созданы.'];
            }
            $this->markDeliveryPending($session);
            $this->cash->amendPaymentIntakeDraft(new AmendPaymentIntakeDraftCommand($companyId, $actor, $draft->id, $revision, retry: true));
            $session->requireDelivery();
        } elseif (in_array(substr($action, 0, 1), ['x', 'i', 'e', 'q'], true)) {
            try {
                $itemId = PaymentIntakePresenter::decodeId(substr($action, 1));
            } catch (\InvalidArgumentException) {
                return ['text' => 'Некорректная строка.'];
            }
            if (!in_array($itemId, array_column($draft->items, 'id'), true) || in_array($draft->status, ['confirmed', 'cancelled'], true)) {
                return ['text' => 'Строка недоступна или черновик завершён.'];
            }
            if ('e' === $action[0]) {
                $session->awaitEdit($itemId, $revision);
                $this->em->flush();

                return ['text' => 'Ответьте на карточку этой строки: «сумма 20000», «дата 2026-11-13», «оплачено», «проект Название», «статья Название» или «ЦФО Название». Для связанной пары: «проект Название; ЦФО Название».'];
            }
            if ('q' === $action[0]) {
                $this->markDeliveryPending($session);
                $this->cash->amendPaymentIntakeDraft(new AmendPaymentIntakeDraftCommand($companyId, $actor, $draft->id, $revision, [$itemId => ['paid_confirmed' => true, 'execution_status' => 'paid', 'questions' => []]]));
                $session->requireDelivery();
            } else {
                $this->markDeliveryPending($session);
                $this->cash->amendPaymentIntakeDraft(new AmendPaymentIntakeDraftCommand($companyId, $actor, $draft->id, $revision, [$itemId => ['excluded' => 'x' === $action[0]]]));
                $session->requireDelivery();
            }
        } else {
            return ['text' => 'Неизвестное действие.'];
        }
        $this->em->flush();
        $this->dispatch($session);

        return ['text' => 'confirm' === $action ? 'Платежи сохранены. Доставляю подтверждение; повтор кнопки не создаст новые операции.' : 'Состояние черновика обновляется.'];
    }

    /** @return array<string, mixed> */
    private function correct(PaymentIntakeSession $session, int $revision, string $text, string $replyMessageId): array
    {
        $itemId = $session->getEditingItemId();
        if (null === $itemId || $session->getEditingRevision() !== $revision) {
            return ['text' => 'Сначала нажмите «Исправить» у нужной строки в актуальной карточке.'];
        }
        $patch = [];
        $text = trim($text);
        if (preg_match('/^проект\s+(.+?)\s*;\s*цфо\s+(.+)$/iu', $text, $pairMatch)) {
            $projectId = $this->resolveChoice($session->getCompanyId(), 'project_id', $pairMatch[1]);
            $centerId = $this->resolveChoice($session->getCompanyId(), 'cfo_id', $pairMatch[2]);
            if (null === $projectId || null === $centerId) {
                return ['text' => 'Проект или ЦФО не найдены однозначно. Укажите точные названия; оба поля сохранятся вместе.'];
            }
            $patch = ['project_id' => $projectId, 'cfo_id' => $centerId];
        } elseif (preg_match('/^(?:измени на|сумма)\s+([0-9]+(?:[.,][0-9]{1,2})?)$/iu', $text, $match)) {
            $patch['amount'] = str_replace(',', '.', $match[1]);
        } elseif (preg_match('/^дата\s+(\d{4}-\d{2}-\d{2})$/iu', $text, $match)) {
            $patch['date'] = $match[1];
        } elseif ('оплачено' === mb_strtolower($text)) {
            $patch['paid_confirmed'] = true;
            $patch['execution_status'] = 'paid';
        } elseif (preg_match('/^(проект|статья|цфо)\s+(.+)$/iu', $text, $match)) {
            $field = match (mb_strtolower($match[1])) {
                'проект' => 'project_id', 'статья' => 'category_id', default => 'cfo_id',
            };
            $choice = $this->resolveChoice($session->getCompanyId(), $field, $match[2]);
            if (null === $choice) {
                return ['text' => 'Название не найдено или неоднозначно. Укажите точное название из справочника компании.'];
            }
            $patch[$field] = $choice;
        } elseif (preg_match('/^назначение\s+(.+)$/ius', $text, $match)) {
            $patch['description'] = $match[1];
        } else {
            return ['text' => 'Назовите одно поле: сумма, дата, оплачено, проект Название, статья Название, ЦФО Название или назначение. Связанную пару можно изменить вместе: «проект Название; ЦФО Название». Изменения не применены.'];
        }
        $this->markDeliveryPending($session);
        $this->cash->amendPaymentIntakeDraft(new AmendPaymentIntakeDraftCommand($session->getCompanyId(), (string) $session->getBinding()->getActorUserId(), $session->getDraftId(), $revision, [$itemId => $patch], replyIdempotencyKey: 'telegram:'.$session->getBinding()->getBot()->getId().':'.$session->getChatId().':'.$replyMessageId));
        $session->clearEdit();
        $session->requireDelivery();
        $this->em->flush();
        $this->dispatch($session);

        return ['text' => 'Правка применена к выбранной строке. Проверьте новую версию.'];
    }

    private function resolveChoice(string $companyId, string $field, string $name): ?string
    {
        $choices = match ($field) {
            'project_id' => $this->companies->listProjectDirections($companyId),
            'category_id' => $this->cash->getCashflowCategoryTree($companyId),
            default => $this->centers->getActiveChoices($companyId),
        };
        $matches = array_values(array_filter($choices, static fn ($choice): bool => mb_strtolower($choice->name) === mb_strtolower(trim($name))));

        return 1 === count($matches) ? $matches[0]->id : null;
    }

    public function selectMoneyAccount(ClientBinding $selected, MoneyAccount $account): void
    {
        if ($account->getCompany()->getId() !== $selected->getCompany()->getId() || !$this->mayUseBinding($selected)) {
            throw new \DomainException('Касса недоступна.');
        }
        $this->em->wrapInTransaction(function () use ($selected, $account): void {
            $key = 'telegram-selection:'.$selected->getBot()->getId().':'.$selected->getTelegramUser()->getId();
            $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
            foreach ($this->em->getRepository(ClientBinding::class)->findBy(['telegramUser' => $selected->getTelegramUser(), 'bot' => $selected->getBot()]) as $binding) {
                $binding->select(false);
            }
            $this->em->flush();
            $selected->setMoneyAccount($account);
            $selected->select(true);
        });
    }

    public function mayUseBinding(ClientBinding $binding, bool $write = true): bool
    {
        return null === $binding->getActorUserId() || $this->companies->financialAccess((string) $binding->getCompany()->getId(), $binding->getActorUserId())[$write ? 'write' : 'read'];
    }

    private function markDeliveryPending(PaymentIntakeSession $session): void
    {
        $session->requireDelivery();
        $this->em->flush();
    }

    private function dispatch(PaymentIntakeSession $session): void
    {
        $session->schedulePolling();
        $this->em->flush();
        ($this->polls)($session);
    }
}
