<?php

declare(strict_types=1);

namespace App\Tests\Support\DoctrineDeprecations;

use Psr\Log\AbstractLogger;

/**
 * PSR-логгер для doctrine/deprecations: запоминает первое срабатывание каждой
 * ссылки — текст, пакет, место в vendor, первую строку кода проекта в стеке и
 * тест, в котором оно случилось.
 *
 * Doctrine дедуплицирует по ссылке, поэтому логгер получает ровно одну запись
 * на deprecation за процесс.
 */
final class DeprecationCollector extends AbstractLogger
{
    private string $currentTest = '(вне теста)';

    /** @var array<string, array{message: string, package: string, trigger: string, caller: string, test: string}> */
    private array $firstSeen = [];

    public function testStarted(string $testId): void
    {
        $this->currentTest = $testId;
    }

    /**
     * @param mixed[] $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $link = is_string($context['link'] ?? null) ? $context['link'] : (string) $message;
        if (isset($this->firstSeen[$link])) {
            return;
        }

        $file = is_string($context['file'] ?? null) ? $context['file'] : '?';
        $line = is_int($context['line'] ?? null) ? $context['line'] : 0;
        $this->firstSeen[$link] = [
            'message' => (string) $message,
            'package' => is_string($context['package'] ?? null) ? $context['package'] : '?',
            'trigger' => sprintf('%s:%d', $file, $line),
            'caller' => self::firstProjectFrame(),
            'test' => $this->currentTest,
        ];
    }

    /**
     * @return array<string, array{message: string, package: string, trigger: string, caller: string, test: string}>
     */
    public function firstSeen(): array
    {
        return $this->firstSeen;
    }

    /**
     * Место в коде проекта, откуда пришёл вызов: `file` из контекста Doctrine —
     * это файл vendor, который сам вызвал trigger(), а не наш вызов.
     */
    private static function firstProjectFrame(): string
    {
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? '';
            if ('' !== $file && !str_contains($file, '/vendor/') && __FILE__ !== $file) {
                return sprintf('%s:%d', $file, $frame['line'] ?? 0);
            }
        }

        return '(только vendor)';
    }
}
