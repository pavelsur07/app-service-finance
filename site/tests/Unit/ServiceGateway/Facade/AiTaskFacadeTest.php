<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Facade;

use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Facade\AiTaskFacade;
use App\ServiceGateway\Message\SubmitAiTaskMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AiTaskFacadeTest extends TestCase
{
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';

    /** @var list<object> */
    private array $dispatched = [];

    public function testDispatchesMessageWithCallerKeyWhenEnabled(): void
    {
        $taskId = $this->facade('*')->requestTask(self::COMPANY, 'echo', ['text' => 'a'], 'source-row-1');

        self::assertNotNull($taskId);
        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0];
        self::assertInstanceOf(SubmitAiTaskMessage::class, $message);
        self::assertSame($taskId, $message->taskId);
        self::assertSame('source-row-1', $message->idempotencyKey);
        self::assertSame('system', $message->requestedBy);
        self::assertSame(['text' => 'a'], $message->payload);
        self::assertSame('2026-10-30T12:00:00+03:00', $message->createdAt);
    }

    public function testSameKeyGivesSameTaskIdAndOtherKeyOrCompanyAnother(): void
    {
        $facade = $this->facade('*');
        $first = $facade->requestTask(self::COMPANY, 'echo', ['text' => 'a'], 'source-row-1');

        self::assertSame($first, $facade->requestTask(self::COMPANY, 'echo', ['text' => 'a'], 'source-row-1'));
        self::assertNotSame($first, $facade->requestTask(self::COMPANY, 'echo', ['text' => 'a'], 'source-row-2'));
        self::assertNotSame($first, $facade->requestTask('0192f0a0-0000-7000-8000-0000000000c2', 'echo', ['text' => 'a'], 'source-row-1'));
        self::assertNotSame($first, $facade->requestTask(self::COMPANY, 'other', ['text' => 'a'], 'source-row-1'));
    }

    public function testDoesNothingWhenCompanyIsNotAllowed(): void
    {
        self::assertNull($this->facade('')->requestTask(self::COMPANY, 'echo', ['text' => 'a'], 'k'));
        self::assertSame([], $this->dispatched);
    }

    private function facade(string $allowlist): AiTaskFacade
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new AiTaskFacade(
            new AiServiceAccess(true, $allowlist, 'http://ai', 'token'),
            $bus,
            new MockClock(new \DateTimeImmutable('2026-10-30T12:00:00+03:00')),
            new NullLogger(),
        );
    }
}
