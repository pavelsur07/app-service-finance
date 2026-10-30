<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\AiCheck;
use App\ServiceGateway\Application\DTO\AiResultsPage;
use App\ServiceGateway\Application\DTO\AiTaskAccepted;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Exception\ContractViolationException;

/**
 * Строгий разбор ответов ai-service по services/_contracts/ai-task/v1/schema.json:
 * лишние и недостающие ключи, типы, UUID, перечисления, даты. Схема повторена
 * вручную (библиотеки JSON Schema в проекте нет); изменение схемы — новая версия
 * контракта и новый парсер.
 *
 * Вход — JSON, декодированный объектами: только так `{}` отличим от `[]`.
 */
final readonly class AiTaskV1Parser
{
    public const CONTRACT = 'ai-task/v1';

    private const ACCEPTED_KEYS = ['task_id', 'status', 'duplicate'];
    private const PAGE_KEYS = ['items', 'next_cursor'];
    private const RESULT_KEYS = [
        'task_id', 'task_type', 'company_id', 'status', 'output', 'checks', 'confidence', 'trace_id',
        'models', 'prompt_version', 'usage', 'error_code', 'completed_at',
    ];
    private const CHECK_KEYS = ['name', 'passed', 'severity', 'details'];
    private const USAGE_KEYS = ['input_tokens', 'output_tokens'];
    private const TASK_STATES = ['queued', 'running', 'done', 'acked'];
    private const SEVERITIES = ['block', 'review', 'info'];
    private const TASK_TYPE = '/^[a-z][a-z0-9_]{1,63}$/D';
    private const MODEL = '/^[a-z0-9_-]+\/.+$/D';
    private const PROMPT_VERSION = '/^[a-z0-9_]+\/v[0-9]+$/D';
    private const ERROR_CODE = '/^[a-z][a-z0-9_]*$/D';
    private const CURSOR = '/^[0-9]+$/D';
    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';
    private const DATE_TIME_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    public function parseAccepted(mixed $data): AiTaskAccepted
    {
        $data = $this->object($data, '$', self::ACCEPTED_KEYS);
        if (!\is_bool($data['duplicate'])) {
            throw $this->violation('$.duplicate', 'must be a boolean');
        }

        return new AiTaskAccepted(
            taskId: $this->uuid($data['task_id'], '$.task_id'),
            status: $this->oneOf($data['status'], '$.status', self::TASK_STATES),
            duplicate: $data['duplicate'],
        );
    }

    public function parseResultsPage(mixed $data): AiResultsPage
    {
        $data = $this->object($data, '$', self::PAGE_KEYS);
        if (!\is_array($data['items']) || !array_is_list($data['items'])) {
            throw $this->violation('$.items', 'must be an array');
        }
        // Конверт страницы строгий; отдельный результат с нарушением откладывается,
        // а не отвергает страницу: иначе один результат останавливал бы весь импорт.
        $items = [];
        $invalid = [];
        foreach ($data['items'] as $i => $item) {
            try {
                $items[] = $this->result($item, sprintf('$.items[%d]', $i));
            } catch (ContractViolationException $e) {
                $invalid[] = sprintf('task_id=%s: %s', $this->readableTaskId($item), $e->getMessage());
            }
        }
        $cursor = $data['next_cursor'];
        if (null !== $cursor && (!\is_string($cursor) || 1 !== preg_match(self::CURSOR, $cursor))) {
            throw $this->violation('$.next_cursor', 'must be a numeric string or null');
        }

        return new AiResultsPage($items, $cursor, $invalid);
    }

    private function result(mixed $value, string $path): AiTaskResult
    {
        $data = $this->object($value, $path, self::RESULT_KEYS);

        $status = AiResultStatus::tryFrom($this->string($data['status'], $path.'.status'));
        if (null === $status) {
            throw $this->violation($path.'.status', 'unknown result status');
        }

        $output = $data['output'];
        if (null !== $output && !$output instanceof \stdClass) {
            throw $this->violation($path.'.output', 'must be an object or null');
        }

        if (!\is_array($data['checks']) || !array_is_list($data['checks'])) {
            throw $this->violation($path.'.checks', 'must be an array');
        }
        $checks = [];
        foreach ($data['checks'] as $i => $check) {
            $checks[] = $this->check($check, sprintf('%s.checks[%d]', $path, $i));
        }

        $confidence = $data['confidence'];
        if (null !== $confidence) {
            if ((!\is_float($confidence) && !\is_int($confidence)) || $confidence < 0 || $confidence > 1) {
                throw $this->violation($path.'.confidence', 'must be a number 0..1 or null');
            }
            $confidence = (float) $confidence;
        }

        if (!\is_array($data['models']) || !array_is_list($data['models'])) {
            throw $this->violation($path.'.models', 'must be an array');
        }
        $models = [];
        foreach ($data['models'] as $i => $model) {
            $models[] = $this->matching($model, sprintf('%s.models[%d]', $path, $i), self::MODEL);
        }

        $usage = $this->object($data['usage'], $path.'.usage', self::USAGE_KEYS);

        return new AiTaskResult(
            taskId: $this->uuid($data['task_id'], $path.'.task_id'),
            taskType: $this->matching($data['task_type'], $path.'.task_type', self::TASK_TYPE),
            companyId: $this->uuid($data['company_id'], $path.'.company_id'),
            status: $status,
            output: null === $output ? null : $this->toArray($output),
            checks: $checks,
            confidence: $confidence,
            traceId: $this->uuid($data['trace_id'], $path.'.trace_id'),
            models: $models,
            promptVersion: null === $data['prompt_version'] ? null : $this->matching($data['prompt_version'], $path.'.prompt_version', self::PROMPT_VERSION),
            inputTokens: $this->count($usage['input_tokens'], $path.'.usage.input_tokens'),
            outputTokens: $this->count($usage['output_tokens'], $path.'.usage.output_tokens'),
            errorCode: null === $data['error_code'] ? null : $this->matching($data['error_code'], $path.'.error_code', self::ERROR_CODE),
            completedAt: $this->dateTime($data['completed_at'], $path.'.completed_at'),
        );
    }

    private function check(mixed $value, string $path): AiCheck
    {
        $data = $this->object($value, $path, self::CHECK_KEYS);
        if (!\is_bool($data['passed'])) {
            throw $this->violation($path.'.passed', 'must be a boolean');
        }
        if (!\is_string($data['details'])) {
            throw $this->violation($path.'.details', 'must be a string');
        }

        return new AiCheck(
            name: $this->string($data['name'], $path.'.name'),
            passed: $data['passed'],
            severity: $this->oneOf($data['severity'], $path.'.severity', self::SEVERITIES),
            details: $data['details'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(\stdClass $value): array
    {
        /** @var array<string, mixed> $array */
        $array = json_decode(json_encode($value, \JSON_THROW_ON_ERROR), true, 64, \JSON_THROW_ON_ERROR);

        return $array;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $path, array $keys): array
    {
        if (!$value instanceof \stdClass) {
            throw $this->violation($path, 'must be an object');
        }
        $value = get_object_vars($value);
        $actual = array_map('strval', array_keys($value));
        $missing = array_diff($keys, $actual);
        if ([] !== $missing) {
            throw $this->violation($path, 'missing '.implode(', ', $missing));
        }
        $extra = array_diff($actual, $keys);
        if ([] !== $extra) {
            throw $this->violation($path, 'unexpected '.implode(', ', $extra));
        }

        /* @var array<string, mixed> $value */
        return $value;
    }

    private function string(mixed $value, string $path): string
    {
        if (!\is_string($value) || '' === $value) {
            throw $this->violation($path, 'must be a non-empty string');
        }

        return $value;
    }

    private function matching(mixed $value, string $path, string $pattern): string
    {
        $value = $this->string($value, $path);
        if (1 !== preg_match($pattern, $value)) {
            throw $this->violation($path, 'has an invalid format');
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, string $path, array $allowed): string
    {
        $value = $this->string($value, $path);
        if (!\in_array($value, $allowed, true)) {
            throw $this->violation($path, 'must be one of '.implode(', ', $allowed));
        }

        return $value;
    }

    private function readableTaskId(mixed $item): string
    {
        $taskId = $item instanceof \stdClass ? ($item->task_id ?? null) : null;

        return \is_string($taskId) && 1 === preg_match(self::UUID, $taskId) ? $taskId : '?';
    }

    private function uuid(mixed $value, string $path): string
    {
        $value = $this->string($value, $path);
        // Шаблон схемы, а не Uuid::isValid: тот принимает `{…}` и `urn:uuid:…`.
        if (1 !== preg_match(self::UUID, $value)) {
            throw $this->violation($path, 'must be a UUID');
        }

        return strtolower($value);
    }

    private function count(mixed $value, string $path): int
    {
        if (!\is_int($value) || $value < 0) {
            throw $this->violation($path, 'must be a non-negative integer');
        }

        return $value;
    }

    private function dateTime(mixed $value, string $path): \DateTimeImmutable
    {
        $raw = $this->string($value, $path);
        foreach (self::DATE_TIME_FORMATS as $format) {
            // createFromFormat нормализует 30 февраля в март и сообщает об этом только предупреждением.
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw);
            if (false !== $parsed && !self::hasParseWarnings()) {
                return $parsed->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }
        }

        throw $this->violation($path, 'must be an RFC 3339 date-time');
    }

    private static function hasParseWarnings(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    }

    private function violation(string $path, string $reason): ContractViolationException
    {
        return ContractViolationException::at(self::CONTRACT, $path, $reason);
    }
}
