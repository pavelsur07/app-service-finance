<?php

declare(strict_types=1);

namespace App\Tests\Support\DoctrineDeprecations;

use Psr\Log\AbstractLogger;

/**
 * PSR-логгер для doctrine/deprecations: запоминает первое срабатывание каждой
 * ссылки — текст, место вызова и тест, в котором оно случилось.
 *
 * Doctrine дедуплицирует по ссылке, поэтому логгер получает ровно одну запись
 * на deprecation за процесс.
 */
final class DeprecationCollector extends AbstractLogger
{
    private string $currentTest = '(вне теста)';

    /** @var array<string, array{message: string, package: string, where: string, test: string}> */
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
            'where' => sprintf('%s:%d', $file, $line),
            'test' => $this->currentTest,
        ];
    }

    /**
     * @return array<string, array{message: string, package: string, where: string, test: string}>
     */
    public function firstSeen(): array
    {
        return $this->firstSeen;
    }
}
