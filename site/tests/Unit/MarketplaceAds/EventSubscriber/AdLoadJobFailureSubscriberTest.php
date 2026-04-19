<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\EventSubscriber;

use App\MarketplaceAds\EventSubscriber\AdLoadJobFailureSubscriber;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Unit-тесты AdLoadJobFailureSubscriber.
 *
 * Проверяемые инварианты:
 *  1. willRetry=true → no-op (Messenger ещё сделает попытку).
 *  2. Message не FetchOzonAdStatisticsMessage → игнорируется.
 *  3. Исчерпанные retries → markFailed с reason вида "<Class>: <message>".
 *  4. HandlerFailedException раскручивается до previous.
 *  5. reason усекается до 1000 символов.
 */
final class AdLoadJobFailureSubscriberTest extends TestCase
{
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testWillRetryTrueIsNoOp(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->expects(self::never())->method('markFailed');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $envelope = new Envelope(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            '2026-03-01',
            '2026-03-03',
        ));
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('transient'));
        $event->setForRetry();

        self::assertTrue($event->willRetry(), 'sanity: событие помечено для ретрая');

        (new AdLoadJobFailureSubscriber($repo, $logger))->onMessageFailed($event);
    }

    public function testNonFetchMessageIsIgnored(): void
    {
        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->expects(self::never())->method('markFailed');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $envelope = new Envelope(new ProcessAdRawDocumentMessage(
            self::COMPANY_ID,
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        ));
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom'));

        self::assertFalse($event->willRetry());

        (new AdLoadJobFailureSubscriber($repo, $logger))->onMessageFailed($event);
    }

    public function testExhaustedRetriesMarkJobAsFailed(): void
    {
        $expectedReason = \RuntimeException::class.': Ozon 502 Bad Gateway';

        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('markFailed')
            ->with(self::JOB_ID, self::COMPANY_ID, $expectedReason)
            ->willReturn(1);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AdLoadJob marked as failed after retries exhausted',
                self::callback(static function (array $context): bool {
                    return self::JOB_ID === $context['job_id']
                        && self::COMPANY_ID === $context['company_id']
                        && FetchOzonAdStatisticsMessage::class === $context['message_type']
                        && \RuntimeException::class === $context['error_class']
                        && 'Ozon 502 Bad Gateway' === $context['error_message'];
                }),
            );

        $envelope = new Envelope(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            '2026-03-01',
            '2026-03-03',
        ));
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'async',
            new \RuntimeException('Ozon 502 Bad Gateway'),
        );

        self::assertFalse($event->willRetry());

        (new AdLoadJobFailureSubscriber($repo, $logger))->onMessageFailed($event);
    }

    public function testHandlerFailedExceptionUnwrapsToPrevious(): void
    {
        $previous = new \RuntimeException('real cause');

        $envelope = new Envelope(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            '2026-03-01',
            '2026-03-03',
        ));
        $wrapper = new HandlerFailedException($envelope, [$previous]);

        self::assertSame($previous, $wrapper->getPrevious(), 'sanity: HandlerFailedException.previous === real cause');

        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('markFailed')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                \RuntimeException::class.': real cause',
            )
            ->willReturn(1);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::anything(),
                self::callback(static function (array $context): bool {
                    return \RuntimeException::class === $context['error_class']
                        && 'real cause' === $context['error_message'];
                }),
            );

        $event = new WorkerMessageFailedEvent($envelope, 'async', $wrapper);

        (new AdLoadJobFailureSubscriber($repo, $logger))->onMessageFailed($event);
    }

    public function testReasonTruncationKeepsUtf8Valid(): void
    {
        // 5000 повторений кириллической буквы (2 байта UTF-8) = 10 000 байт.
        // Байтовое substr порвало бы последний символ; mb_substr режет по символам.
        $longMessage = str_repeat('ё', 5000);

        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('markFailed')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::callback(static function (string $reason): bool {
                    return mb_strlen($reason, 'UTF-8') <= 1000
                        && mb_check_encoding($reason, 'UTF-8');
                }),
            )
            ->willReturn(1);

        $logger = $this->createMock(LoggerInterface::class);

        $envelope = new Envelope(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            '2026-03-01',
            '2026-03-03',
        ));
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException($longMessage));

        (new AdLoadJobFailureSubscriber($repo, $logger))->onMessageFailed($event);
    }

    public function testReasonIsTruncatedTo1000Chars(): void
    {
        $longMessage = str_repeat('A', 5000);

        $repo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('markFailed')
            ->with(
                self::JOB_ID,
                self::COMPANY_ID,
                self::callback(static function (string $reason): bool {
                    return mb_strlen($reason, 'UTF-8') <= 1000;
                }),
            )
            ->willReturn(1);

        $logger = $this->createMock(LoggerInterface::class);

        $envelope = new Envelope(new FetchOzonAdStatisticsMessage(
            self::JOB_ID,
            self::COMPANY_ID,
            '2026-03-01',
            '2026-03-03',
        ));
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException($longMessage));

        (new AdLoadJobFailureSubscriber($repo, $logger))->onMessageFailed($event);
    }
}
