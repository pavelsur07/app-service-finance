<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Infrastructure\Http\ApiSecretLogProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class ApiSecretLogProcessorTest extends TestCase
{
    public function testMasksTokensAndApiSqlParameters(): void
    {
        $token = 'vfd_'.str_repeat('a', 32).'.'.str_repeat('b', 64);
        $record = new LogRecord(new \DateTimeImmutable(), 'doctrine', Level::Debug, 'Executing '.$token, [
            'sql' => 'INSERT INTO api_keys (secret_hash) VALUES (?)',
            'params' => [str_repeat('c', 64)],
            'nested' => ['api_key' => $token],
        ]);
        $safe = (new ApiSecretLogProcessor())($record);
        self::assertStringNotContainsString($token, $safe->message);
        self::assertSame('[Filtered]', $safe->context['params']);
        self::assertSame('[Filtered]', $safe->context['nested']['api_key']);
    }
}
