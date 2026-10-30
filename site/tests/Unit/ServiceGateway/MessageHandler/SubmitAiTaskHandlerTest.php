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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
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

    public function testDisabledCompanyIsRetriedLater(): void
    {
        $client = new SubmitAiTaskRecordingClient();

        $this->expectException(RecoverableMessageHandlingException::class);
        try {
            $this->handler($client, '')($this->message());
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testUnavailableServiceIsRetried(): void
    {
        $client = new SubmitAiTaskRecordingClient(ServiceUnavailableException::status('ai-service', 503));

        $this->expectException(RecoverableMessageHandlingException::class);
        $this->handler($client)($this->message());
    }

    public function testRejectedTaskIsNotRetried(): void
    {
        $client = new SubmitAiTaskRecordingClient(AiTaskRejectedException::status(409, 'idempotency_conflict'));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->handler($client)($this->message());
    }

    private function handler(AiServiceClientInterface $client, string $allowlist = '*'): SubmitAiTaskHandler
    {
        return new SubmitAiTaskHandler($client, new AiServiceAccess(true, $allowlist, 'http://ai', 'token'), new NullLogger());
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

    public function __construct(private readonly ?\Throwable $error = null)
    {
    }

    public function submitTask(array $request): AiTaskAccepted
    {
        if (null !== $this->error) {
            throw $this->error;
        }
        $this->requests[] = $request;
        $taskId = $request['task_id'];
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
