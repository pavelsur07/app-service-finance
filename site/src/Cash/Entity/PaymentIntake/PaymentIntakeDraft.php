<?php

declare(strict_types=1);

namespace App\Cash\Entity\PaymentIntake;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\PaymentIntake\PaymentIntakeDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PaymentIntakeDraftRepository::class)]
#[ORM\Table(name: 'cash_payment_intake_draft')]
#[ORM\UniqueConstraint(name: 'uniq_payment_intake_source', columns: ['source_key'])]
#[ORM\Index(name: 'idx_payment_intake_company_status', columns: ['company_id', 'status'])]
#[ORM\Index(name: 'idx_payment_intake_task', columns: ['company_id', 'task_id'])]
class PaymentIntakeDraft
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;
    #[ORM\Column(type: Types::GUID)]
    private string $companyId;
    #[ORM\Column(type: Types::GUID)]
    private string $actorUserId;
    #[ORM\Column(type: Types::GUID)]
    private string $moneyAccountId;
    #[ORM\Column(length: 255)]
    private string $sourceKey;
    #[ORM\Column(length: 64)]
    private string $sourceRevision;
    #[ORM\Column(type: Types::TEXT)]
    private string $text;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;
    #[ORM\Column(length: 64)]
    private string $timezone;
    #[ORM\Column(length: 3)]
    private string $currency;
    #[ORM\Column]
    private int $revision = 1;
    #[ORM\Column]
    private int $attempt = 1;
    #[ORM\Column]
    private int $taskRevision = 1;
    #[ORM\Column(length: 24)]
    private string $status = 'pending';
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $taskId = null;
    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSONB)]
    private array $items = [];
    /** @var list<string> */
    #[ORM\Column(type: Types::JSONB)]
    private array $questions = [];
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $payload = [];
    /** @var list<string> */
    #[ORM\Column(type: Types::JSONB)]
    private array $transactionIds = [];
    /** @var list<string> */
    #[ORM\Column(type: Types::JSONB)]
    private array $confirmedItemIds = [];
    #[ORM\Column(nullable: true)]
    private ?int $confirmedRevision = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;
    #[ORM\Column]
    private bool $dispatchPending = true;
    #[ORM\Column]
    private bool $cashEventPending = false;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastEnqueuedAt = null;

    public function __construct(string $companyId, string $actorUserId, string $moneyAccountId, string $sourceKey, string $sourceRevision, string $text, \DateTimeImmutable $receivedAt, string $timezone, string $currency)
    {
        Assert::uuid($companyId);
        Assert::uuid($actorUserId);
        Assert::uuid($moneyAccountId);
        Assert::maxLength($sourceKey, 255);
        Assert::maxLength($sourceRevision, 64);
        Assert::notEmpty(trim($text));
        Assert::maxLength($text, 16000);
        new \DateTimeZone($timezone);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->actorUserId = $actorUserId;
        $this->moneyAccountId = $moneyAccountId;
        $this->sourceKey = $sourceKey;
        $this->sourceRevision = $sourceRevision;
        $this->text = $text;
        $this->receivedAt = $receivedAt;
        $this->timezone = $timezone;
        $this->currency = $currency;
    }

    /** @param array<string, mixed> $payload */
    public function queue(string $taskId, array $payload = []): void
    {
        Assert::uuid($taskId);
        $this->taskId = $taskId;
        $this->taskRevision = $this->revision;
        $this->payload = $payload;
        $this->dispatchPending = true;
        $this->status = 'pending';
        $this->errorCode = null;
        $this->lastEnqueuedAt = null;
    }

    public function markDispatched(string $taskId, ?\DateTimeImmutable $now = null): void
    {
        if ($taskId === $this->taskId && $this->taskRevision === $this->revision && \in_array($this->status, ['pending', 'queued'], true)) {
            $this->dispatchPending = false;
            $this->lastEnqueuedAt = $now ?? new \DateTimeImmutable();
            if ('pending' === $this->status) {
                $this->status = 'queued';
            }
        }
    }

    public function needsRedispatch(\DateTimeImmutable $now): bool
    {
        return 'queued' === $this->status && $this->taskRevision === $this->revision && [] !== $this->payload
            && (null === $this->lastEnqueuedAt || $this->lastEnqueuedAt <= $now->modify('-60 seconds'));
    }

    /** Reserve the throttle interval under the aggregate lock before enqueueing. */
    public function reserveRedispatch(\DateTimeImmutable $now): bool
    {
        if (!$this->needsRedispatch($now)) {
            return false;
        }
        $this->lastEnqueuedAt = $now;

        return true;
    }

    public function fail(string $taskId, string $error): void
    {
        if ($taskId !== $this->taskId || $this->taskRevision !== $this->revision || !\in_array($this->status, ['pending', 'queued'], true)) {
            return;
        }
        $this->status = 'failed';
        $this->dispatchPending = false;
        $this->errorCode = substr($error, 0, 64);
        $this->payload = [];
    }

    /** @param array<string, mixed> $output */
    public function acceptResult(string $taskId, array $output): bool
    {
        if ($taskId !== $this->taskId || $this->taskRevision !== $this->revision || !\in_array($this->status, ['pending', 'queued'], true)) {
            return false;
        }
        if ('correction' === $output['intent']) {
            $this->applyCorrection($output);
            $this->status = 'needs_clarification';
            $this->dispatchPending = false;

            $this->payload = [];

            return true;
        }
        $items = [];
        $previous = array_column($this->items, null, 'id');
        $this->questions = $output['questions'];
        foreach ($output['items'] as $candidate) {
            $candidate['id'] = Uuid::uuid7()->toString();
            $candidate['transaction_id'] = Uuid::uuid7()->toString();
            $candidate['paid_confirmed'] = false;
            $candidate['provenance'] = ['amount' => 'model', 'execution_status' => 'model'];
            if (null === $candidate['date']) {
                $candidate['date'] = $this->receivedAt->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d');
                $candidate['provenance']['date'] = 'message_date_default';
            }
            if (null === $candidate['currency']) {
                $candidate['currency'] = $this->currency;
                $candidate['provenance']['currency'] = 'selected_account_default';
            }
            $old = $previous[$candidate['ref']] ?? null;
            if (null !== $old) {
                $candidate['id'] = $old['id'];
                $candidate['transaction_id'] = $old['transaction_id'];
                if (isset($old['excluded'])) {
                    $candidate['excluded'] = $old['excluded'];
                }
                foreach ($old['provenance'] as $field => $provenance) {
                    if ('user' !== $provenance || \in_array($field, ['paid_confirmed', 'questions'], true) || !\array_key_exists($field, $candidate)) {
                        continue;
                    }
                    if ($candidate[$field] !== $old[$field]) {
                        $candidate['questions'][] = $this->manualConflictQuestion($field);
                    }
                    $candidate[$field] = $old[$field];
                    $candidate['provenance'][$field] = 'user';
                }
                unset($previous[$old['id']]);
            }
            $items[] = $candidate;
        }
        foreach ($previous as $old) {
            $old['paid_confirmed'] = false;
            if (!($old['excluded'] ?? false)) {
                $old['questions'][] = 'Строка не сопоставлена с изменённым сообщением. Исправьте её вручную или исключите перед подтверждением.';
            }
            $items[] = $old;
        }
        if ([] !== $previous) {
            $this->questions[] = 'После изменения сообщения сохранены несопоставленные прежние строки. Проверьте сопоставление и исключите повторы.';
        }
        $this->items = $items;
        if ('payments' !== $output['intent']) {
            $this->questions[] = 'Платежи не найдены.';
        }
        $this->status = 'needs_clarification';
        $this->dispatchPending = false;

        $this->payload = [];

        return true;
    }

    public function applyGeneralPairDefault(?string $projectId, ?string $cfoId): void
    {
        foreach ($this->items as &$item) {
            if ($item['excluded'] ?? false) {
                continue;
            }
            if (null === $item['project_id'] && null === $item['cfo_id']) {
                if (null === $projectId || null === $cfoId) {
                    if (!\in_array('Укажите проект и ЦФО.', $item['questions'], true)) {
                        $item['questions'][] = 'Укажите проект и ЦФО.';
                    }
                } else {
                    $item['project_id'] = $projectId;
                    $item['cfo_id'] = $cfoId;
                    $item['provenance']['project_id'] = 'system_general_default';
                    $item['provenance']['cfo_id'] = 'system_general_default';
                }
            }
        }
        unset($item);
    }

    /** @param array<string, mixed> $output */
    private function applyCorrection(array $output): void
    {
        $this->questions = $output['questions'];
        $target = $output['target_ref'];
        if (!\is_string($target) || 1 !== \count($output['items'])) {
            $this->questions[] = 'Укажите одну исправляемую строку черновика.';

            return;
        }
        foreach ($output['items'] as $correction) {
            $matched = false;
            foreach ($this->items as &$item) {
                if ($item['id'] !== $target) {
                    continue;
                }
                $matched = true;
                $item['questions'] = $correction['questions'];
                foreach (['amount', 'currency', 'date', 'direction', 'execution_status', 'description', 'category_id', 'project_id', 'cfo_id'] as $field) {
                    $value = $correction[$field];
                    if (null === $value || '' === $value || ('unknown' === $value && 'execution_status' === $field)) {
                        continue;
                    }
                    if ('user' === ($item['provenance'][$field] ?? null)) {
                        if ($item[$field] !== $value) {
                            $item['questions'][] = $this->manualConflictQuestion($field);
                        }
                        continue;
                    }
                    if ($item[$field] !== $value) {
                        $item['paid_confirmed'] = false;
                    }
                    $item[$field] = $value;
                    $item['provenance'][$field] = 'model_correction';
                }
                break;
            }
            unset($item);
            if (!$matched) {
                $this->questions[] = 'Укажите исправляемую строку черновика.';
            }
        }
        if ([] === $output['items']) {
            $this->questions[] = 'Укажите исправляемую строку черновика.';
        }
    }

    /**
     * @param array<string, array<string, mixed>> $patches
     * @param list<string>|null $questions
     */
    public function amend(int $expectedRevision, array $patches, ?string $text = null, ?string $sourceRevision = null, ?array $questions = null, bool $retry = false): bool
    {
        if (null !== $text && [] !== $patches) {
            throw new \DomainException('Передайте исправления полей или новый текст отдельным действием.');
        }
        // A transport replay of the same edit is inert even after the first revision increment.
        if (null !== $sourceRevision && $sourceRevision === $this->sourceRevision) {
            return false;
        }
        // Compare Telegram edit ordering while the caller holds the draft row lock.
        // A webhook can hold a stale Telegram session yet read the latest draft revision.
        if (null !== $sourceRevision
            && 1 === preg_match('/^edit:([0-9]+):([0-9]+):[0-9a-f]+$/D', $sourceRevision, $incomingEdit)
            && 1 === preg_match('/^edit:([0-9]+):([0-9]+):[0-9a-f]+$/D', $this->sourceRevision, $currentEdit)
            && [(int) $incomingEdit[1], (int) $incomingEdit[2]] <= [(int) $currentEdit[1], (int) $currentEdit[2]]) {
            return false;
        }
        $this->assertMutable($expectedRevision);
        if (null !== $questions) {
            $this->validateQuestions($questions);
        }
        if ($retry && 'failed' !== $this->status) {
            throw new \DomainException('Повторить разбор можно после завершённой ошибки.');
        }
        $known = array_column($this->items, 'id');
        foreach ($patches as $id => $patch) {
            if (!\in_array($id, $known, true)) {
                throw new \DomainException('Строка черновика не найдена.');
            }
            foreach ($patch as $key => $value) {
                $this->validatePatchValue($key, $value);
            }
        }
        if (null !== $text) {
            Assert::notEmpty(trim($text));
            Assert::maxLength($text, 16000);
            if (null === $sourceRevision) {
                throw new \DomainException('Не указана версия сообщения.');
            }
            Assert::maxLength($sourceRevision, 64);
            $this->text = $text;
            $this->sourceRevision = $sourceRevision;
            $this->questions = [];
            foreach ($this->items as &$item) {
                $item['paid_confirmed'] = false;
            }
            unset($item);
        } else {
            foreach ($this->items as &$item) {
                $patch = $patches[$item['id']] ?? [];
                foreach (['amount', 'currency', 'date', 'direction', 'execution_status', 'category_id', 'project_id', 'cfo_id'] as $field) {
                    if (\array_key_exists($field, $patch) && $patch[$field] !== $item[$field] && true !== ($patch['paid_confirmed'] ?? null)) {
                        $item['paid_confirmed'] = false;
                    }
                }
                foreach ($patch as $key => $value) {
                    $item[$key] = $value;
                    $item['provenance'][$key] = 'user';
                }
            }
            unset($item);
        }
        if (null !== $questions) {
            $this->questions = $questions;
        }
        ++$this->revision;
        if ($retry || null !== $text) {
            ++$this->attempt;
            $this->status = 'pending';
            $this->dispatchPending = true;
        } else {
            $this->status = $this->isReady() ? 'ready' : 'needs_clarification';
            $this->dispatchPending = false;
            $this->payload = [];
        }

        return true;
    }

    /**
     * @param list<string> $itemIds
     *
     * @return list<array<string, mixed>>
     */
    public function selectedForConfirmation(int $expectedRevision, array $itemIds): array
    {
        $this->assertMutable($expectedRevision);
        if (!\in_array($this->status, ['ready', 'needs_clarification'], true) || [] !== $this->questions || [] === $itemIds || \count($itemIds) !== \count(array_unique($itemIds))) {
            throw new \DomainException('Сначала ответьте на вопросы и выберите платежи.');
        }
        foreach ($this->items as $item) {
            if (!($item['excluded'] ?? false) && !\in_array($item['id'], $itemIds, true)) {
                throw new \DomainException('Выберите все неисключённые платежи или явно исключите оставшиеся строки.');
            }
        }
        $selected = [];
        foreach ($itemIds as $id) {
            $matches = array_filter($this->items, static fn (array $item): bool => $item['id'] === $id);
            $item = array_values($matches)[0] ?? null;
            if (null === $item || ($item['excluded'] ?? false) || true !== ($item['paid_confirmed'] ?? false) || 'paid' !== $item['execution_status'] || [] !== $item['questions']) {
                throw new \DomainException('Подтвердите факт оплаты и разрешите вопросы каждой строки.');
            }
            $selected[] = $item;
        }

        return $selected;
    }

    /**
     * @param list<string> $itemIds
     * @param list<string> $transactionIds
     */
    public function confirm(int $expectedRevision, array $itemIds, array $transactionIds): void
    {
        $this->assertMutable($expectedRevision);
        $this->confirmedRevision = $expectedRevision;
        $this->confirmedItemIds = $itemIds;
        $this->transactionIds = $transactionIds;
        $this->status = 'confirmed';
        $this->payload = [];
        $this->dispatchPending = false;
        $this->cashEventPending = true;
    }

    /** @param list<string> $itemIds */
    public function confirmationMatches(int $revision, array $itemIds): bool
    {
        $actual = $this->confirmedItemIds;
        sort($actual);
        sort($itemIds);

        return 'confirmed' === $this->status && $revision === $this->confirmedRevision && $actual === $itemIds;
    }

    public function cancel(int $expectedRevision): void
    {
        if ('cancelled' === $this->status && $expectedRevision === $this->revision) {
            return;
        }
        $this->assertMutable($expectedRevision);
        $this->status = 'cancelled';
        $this->payload = [];
        $this->dispatchPending = false;
    }

    private function validatePatchValue(string $key, mixed $value): void
    {
        if (!\in_array($key, ['amount', 'currency', 'date', 'direction', 'execution_status', 'description', 'category_id', 'project_id', 'cfo_id', 'questions', 'paid_confirmed', 'excluded'], true)) {
            throw new \DomainException('Недопустимое поле исправления.');
        }
        if (\in_array($key, ['paid_confirmed', 'excluded'], true)) {
            if (!\is_bool($value)) {
                throw new \DomainException('Подтверждение должно быть явным.');
            }

            return;
        }
        if ('questions' === $key) {
            $this->validateQuestions($value);

            return;
        }
        if (null === $value && !\in_array($key, ['description', 'execution_status'], true)) {
            return;
        }
        if (!\is_string($value)) {
            throw new \DomainException('Некорректное значение поля.');
        }
        $valid = match ($key) {
            'description' => mb_strlen($value) <= CashTransaction::DESCRIPTION_MAX_LENGTH,
            'amount' => 1 === preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,2})?$/D', $value) && bccomp($value, '0', 2) > 0,
            'currency' => 1 === preg_match('/^[A-Z]{3}$/D', $value),
            'direction' => \in_array($value, ['inflow', 'outflow'], true),
            'execution_status' => \in_array($value, ['paid', 'planned', 'unknown'], true),
            'date' => $this->validPatchDate($value),
            default => Uuid::isValid($value),
        };
        if (!$valid) {
            throw new \DomainException('Некорректное значение или превышена допустимая длина поля.');
        }
    }

    private function validateQuestions(mixed $values): void
    {
        if (!\is_array($values) || !array_is_list($values) || \count($values) > 50
            || [] !== array_filter($values, static fn ($value): bool => !\is_string($value) || mb_strlen($value) < 1 || mb_strlen($value) > 500)) {
            throw new \DomainException('Вопросы должны содержать до 50 строк длиной от 1 до 500 символов.');
        }
    }

    private function validPatchDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value;
    }

    private function manualConflictQuestion(string $field): string
    {
        $label = match ($field) {
            'amount' => 'суммы', 'currency' => 'валюты', 'date' => 'даты', 'direction' => 'направления',
            'category_id' => 'статьи', 'project_id' => 'проекта', 'cfo_id' => 'ЦФО',
            'description' => 'назначения', 'execution_status' => 'факта оплаты', default => 'поля',
        };

        return 'Сохранён ручной выбор '.$label.', который отличается от нового разбора. Проверьте значение или исправьте его вручную.';
    }

    private function isReady(): bool
    {
        if ([] !== $this->questions) {
            return false;
        }
        $active = false;
        foreach ($this->items as $item) {
            if ($item['excluded'] ?? false) {
                continue;
            }
            $active = true;
            $date = \is_string($item['date']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $item['date']) : false;
            if (true !== $item['paid_confirmed'] || 'paid' !== $item['execution_status'] || [] !== $item['questions']
                || !\is_string($item['amount']) || 1 !== preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,2})?$/D', $item['amount']) || bccomp($item['amount'], '0', 2) <= 0
                || !\in_array($item['direction'], ['inflow', 'outflow'], true) || !\is_string($item['currency'])
                || false === $date || $date->format('Y-m-d') !== $item['date']) {
                return false;
            }
        }

        return $active;
    }

    private function assertMutable(int $expectedRevision): void
    {
        if ($expectedRevision !== $this->revision || \in_array($this->status, ['confirmed', 'cancelled'], true)) {
            throw new \DomainException('Черновик уже изменён или закрыт. Обновите карточку.');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getActorUserId(): string
    {
        return $this->actorUserId;
    }

    public function getMoneyAccountId(): string
    {
        return $this->moneyAccountId;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function getSourceRevision(): string
    {
        return $this->sourceRevision;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTaskId(): ?string
    {
        return $this->taskId;
    }

    /** @return list<array<string, mixed>> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return list<string> */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @return list<string> */
    public function getTransactionIds(): array
    {
        return $this->transactionIds;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function isDispatchPending(): bool
    {
        return $this->dispatchPending;
    }

    public function isCashEventPending(): bool
    {
        return $this->cashEventPending;
    }

    public function acknowledgeCashEvent(): void
    {
        $this->cashEventPending = false;
    }
}
