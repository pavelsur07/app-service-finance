<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\MessageHandler;

use App\ServiceGateway\Application\DTO\AiResultsPage;
use App\ServiceGateway\Application\DTO\AiTaskAccepted;
use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Exception\AiTaskRejectedException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\AiServiceClientInterface;
use App\ServiceGateway\Message\SubmitAiTaskMessage;
use App\ServiceGateway\MessageHandler\SubmitAiTaskHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class SubmitAiTaskHandlerTest extends TestCase
{
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';

    public function testSubmitsEnvelopeBuiltFromMessage(): void
    {
        $client = new SubmitAiTaskRecordingClient();
        $this->handler($client)($this->message());

        self::assertCount(1, $client->requests);
        $request = $client->requests[0];
        self::assertSame('v1', $request['contract_version']);
        self::assertSame('key-1', $request['idempotency_key']);
        self::assertSame(['text' => 'a'], $request['payload']);
    }

    public function testRetrySendsSameIdempotencyKeyAndTaskId(): void
    {
        $client = new SubmitAiTaskRecordingClient();
        $message = $this->message();
        $this->handler($client)($message);
        $this->handler($client)($message);

        self::assertSame($client->requests[0]['idempotency_key'], $client->requests[1]['idempotency_key']);
        self::assertSame($client->requests[0]['task_id'], $client->requests[1]['task_id']);
    }

    public function testDisabledCompanyDropsMessageWithWarning(): void
    {
        $client = new SubmitAiTaskRecordingClient();
        $logs = new TestHandler();

        $this->handler($client, '', $logs)($this->message());

        self::assertSame([], $client->requests);
        self::assertTrue($logs->hasWarningThatContains('ai-service is disabled for the company'));
    }

    public function testUnavailableServiceIsRetriedWithinTransportLimit(): void
    {
        $client = new SubmitAiTaskRecordingClient(ServiceUnavailableException::status('ai-service', 503));

        try {
            $this->handler($client)($this->message());
            self::fail('expected ServiceUnavailableException');
        } catch (\Throwable $e) {
            // Recoverable-маркер обходит max_retries транспорта: сообщение не дошло бы до failed.
            self::assertNotInstanceOf(RecoverableExceptionInterface::class, $e);
            self::assertInstanceOf(ServiceUnavailableException::class, $e);
        }
    }

    public function testServiceTaskIdMismatchIsLogged(): void
    {
        $client = new SubmitAiTaskRecordingClient(acceptedTaskId: '0192f0a0-0000-7000-8000-0000000000ff');
        $logs = new TestHandler();

        $this->handler($client, '*', $logs)($this->message());

        self::assertTrue($logs->hasWarningThatContains('merged into a task with another id'));
    }

    public function testRejectedTaskIsNotRetried(): void
    {
        $client = new SubmitAiTaskRecordingClient(AiTaskRejectedException::status(409, 'idempotency_conflict'));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->handler($client)($this->message());
    }

    private function handler(AiServiceClientInterface $client, string $allowlist = '*', ?TestHandler $logs = null): SubmitAiTaskHandler
    {
        return new SubmitAiTaskHandler(
            $client,
            new AiServiceAccess(true, $allowlist, 'http://ai', 'token'),
            new Logger('test', [$logs ?? new TestHandler()]),
        );
    }

    private function message(): SubmitAiTaskMessage
    {
        return new SubmitAiTaskMessage(
            taskId: '0192f0a0-0000-7000-8000-000000000001',
            taskType: 'echo',
            companyId: self::COMPANY,
            idempotencyKey: 'key-1',
            requestedBy: 'system',
            traceId: '0192f0a0-0000-7000-8000-0000000000f1',
            createdAt: '2026-10-30T12:00:00+03:00',
            payload: ['text' => 'a'],
        );
    }
}

final class SubmitAiTaskRecordingClient implements AiServiceClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function __construct(
        private readonly ?\Throwable $error = null,
        private readonly ?string $acceptedTaskId = null,
    ) {
    }

    public function submitTask(array $request): AiTaskAccepted
    {
        if (null !== $this->error) {
            throw $this->error;
        }
        $this->requests[] = $request;
        $taskId = $this->acceptedTaskId ?? $request['task_id'];
        \assert(\is_string($taskId));

        return new AiTaskAccepted($taskId, 'queued', \count($this->requests) > 1);
    }

    public function fetchResults(?string $after, int $limit): AiResultsPage
    {
        return new AiResultsPage([], null);
    }

    public function ack(array $taskIds): int
    {
        return 0;
    }
}
