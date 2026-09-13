<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Http;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Also protects local Monolog sinks; Sentry already has its own scrubber. */
#[AsMonologProcessor]
final readonly class ApiSecretLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        if (isset($context['sql']) && \is_string($context['sql']) && str_contains(strtolower($context['sql']), 'api_keys')) {
            $context['params'] = '[Filtered]';
        }

        return $record->with(message: $this->mask($record->message), context: $this->scrub($context), extra: $this->scrub($record->extra));
    }

    /** @param array<mixed> $values
     * @return array<mixed>
     */
    private function scrub(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($key) && 1 === preg_match('/secret|authorization|api.?key|bearer/i', $key)) {
                $values[$key] = '[Filtered]';
            } elseif (\is_array($value)) {
                $values[$key] = $this->scrub($value);
            } elseif (\is_string($value)) {
                $values[$key] = $this->mask($value);
            }
        }

        return $values;
    }

    private function mask(string $value): string
    {
        return preg_replace('/vfd_[a-f0-9]{32}\.[a-f0-9]{64}/', '[Filtered]', $value) ?? '[Filtered]';
    }
}
