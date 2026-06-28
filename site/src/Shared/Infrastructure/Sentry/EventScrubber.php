<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * before_send-колбэк: вычищает секреты и PII из событий до отправки в GlitchTip.
 *
 * Защита «в глубину»: SDK с send_default_pii=false уже не шлёт cookies и санитизирует
 * заголовки, но НЕ трогает `extra` (туда fillExtraContext кладёт context-массивы Monolog,
 * где могут оказаться токены/пароли). Поэтому основная ценность — рекурсивная чистка `extra`.
 */
final readonly class EventScrubber
{
    private const REDACTED = '[Filtered]';

    /** Подстрочное совпадение (case-insensitive) по ключам массивов. */
    private const SENSITIVE_KEY_SUBSTRINGS = [
        'password', 'passwd', 'secret', 'token', 'authorization',
        'api_key', 'apikey', 'api-key', 'access_key', 'accesskey',
        'private_key', 'privatekey', 'client_secret', 'credential',
        'bearer', 'cookie', 'csrf', 'session_id', 'sessionid',
    ];

    /** Точное совпадение ключа (case-insensitive) — короткие/неоднозначные имена. */
    private const SENSITIVE_KEY_EXACT = [
        'auth', 'inn', 'pan', 'cvv', 'cvc', 'passport', 'snils', 'pin', 'otp',
    ];

    /** Имена HTTP-заголовков под чистку (в нижнем регистре). */
    private const SENSITIVE_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'x-auth-token',
    ];

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $event->setExtra($this->scrubArray($event->getExtra()));
        $event->setTags($this->scrubTags($event->getTags()));

        $request = $event->getRequest();
        if ([] !== $request) {
            $event->setRequest($this->scrubRequest($request));
        }

        return $event;
    }

    /**
     * @param array<string, string> $tags
     *
     * @return array<string, string>
     */
    private function scrubTags(array $tags): array
    {
        foreach ($tags as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $tags[$key] = self::REDACTED;
            }
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function scrubRequest(array $request): array
    {
        // Тело и окружение — рекурсивная чистка по ключам.
        foreach (['data', 'env'] as $section) {
            if (isset($request[$section]) && \is_array($request[$section])) {
                $request[$section] = $this->scrubArray($request[$section]);
            }
        }

        // Cookies сами по себе чувствительны (сессии/токены) — чистим значения целиком.
        if (isset($request['cookies']) && \is_array($request['cookies'])) {
            foreach ($request['cookies'] as $name => $_value) {
                $request['cookies'][$name] = self::REDACTED;
            }
        }

        // Заголовки — чистим только чувствительные по имени.
        if (isset($request['headers']) && \is_array($request['headers'])) {
            foreach ($request['headers'] as $name => $_value) {
                if (\in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)) {
                    $request['headers'][$name] = self::REDACTED;
                }
            }
        }

        return $request;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (\is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        if (\in_array($lower, self::SENSITIVE_KEY_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
