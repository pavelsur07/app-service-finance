<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\Action\AiQualityAction;
use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Infrastructure\Client\AiServiceClient;
use App\ServiceGateway\Infrastructure\Client\AiTaskV1Parser;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiQualityActionTest extends IntegrationTestCase
{
    private const RUN = '0192f0a0-0000-7000-8000-000000000001';
    private const TRACE = '0192f0a0-0000-7000-8000-000000000002';
    private const FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private MockClock $clock;
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-14T01:45:00+00:00');
        $this->logs = new TestHandler();
    }

    public function testDisabledDoesNotCallServiceOrCreateMonitor(): void
    {
        $action = $this->action(new MockHttpClient(static fn () => self::fail('no HTTP when disabled')), false);

        self::assertSame('disabled', $action->request());
        self::assertSame('disabled', $action->pull());
        self::assertSame('disabled', $action->health());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM ai_quality_monitor'));
    }

    public function testDisabledCommandsAreRegisteredAndSafe(): void
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        foreach (['request', 'pull', 'health-check'] as $operation) {
            $command = new CommandTester($application->find('app:ai-service:quality:'.$operation));
            self::assertSame(Command::SUCCESS, $command->execute([]));
            self::assertStringContainsString('disabled', $command->getDisplay());
        }
    }

    public function testLostPostResponseRetriesSameDurableIdentityAndStopsAfterAcceptance(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $request = json_decode($options['body'], true, 32, \JSON_THROW_ON_ERROR);
            $seen[] = $request;
            if (1 === \count($seen)) {
                throw new TransportException('response lost');
            }

            return new MockResponse(json_encode([
                'contract_version' => 'v1', 'run_id' => self::RUN, 'status' => 'queued',
                'duplicate' => true, 'configuration_fingerprint' => self::FP,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 202]);
        });
        $action = $this->action($http);

        self::assertSame('unavailable', $action->request());
        self::assertSame('accepted', $action->request());
        self::assertSame('already_requested', $action->request());
        self::assertCount(2, $seen);
        self::assertSame($seen[0], $seen[1]);
        self::assertSame('payment_intake:2026-11-14', $seen[0]['idempotency_key']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM ai_quality_run'));
        self::assertSame(self::RUN, $this->connection->fetchOne('SELECT remote_id::text FROM ai_quality_run'));
    }

    public function testDailyWindowUsesMoscowDate(): void
    {
        $this->clock->modify('2026-11-14T00:24:00+00:00');
        /** @var \ArrayObject<int, array<string, mixed>> $requests */
        $requests = new \ArrayObject();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode($options['body'], true, 32, \JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode([
                'contract_version' => 'v1', 'run_id' => self::RUN, 'status' => 'queued',
                'duplicate' => false, 'configuration_fingerprint' => self::FP,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 202]);
        });
        $action = $this->action($http);

        self::assertSame('waiting', $action->request());
        self::assertSame([], $requests->getArrayCopy());
        $this->clock->modify('+1 minute');
        self::assertSame('accepted', $action->request());
        self::assertSame('2026-11-14', $requests[0]['run_date'] ?? null);
    }

    public function testSuccessfulPullIsIdempotentAndHealthDoesNotNeedCompanies(): void
    {
        $action = $this->action($this->http($this->latest()));

        self::assertSame('updated', $action->pull());
        self::assertSame('updated', $action->pull());
        self::assertSame('passed', $action->health());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM ai_quality_run'));
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testOldSuccessfulVersionIsNotCurrentSuccess(): void
    {
        $latest = $this->latest();
        $latest['configuration_fingerprint'] = str_repeat('b', 64);
        $action = $this->action($this->http($latest));

        $action->pull();
        self::assertSame('version_changed', $action->health());
        self::assertTrue($this->logs->hasErrorRecords());
    }

    public function testMissingPullBecomesVisibleAndIncidentIsDeduplicated(): void
    {
        $action = $this->action($this->http($this->latest()));
        $action->pull();
        $this->clock->modify('+21 minutes');

        self::assertSame('pull_stale', $action->health());
        self::assertSame('pull_stale', $action->health());
        self::assertCount(1, $this->logs->getRecords());
        self::assertSame('PaymentIntake nightly quality check failed.', $this->logs->getRecords()[0]->message);
    }

    public function testRecoveryIsLoggedOnceAfterNextSuccessfulDay(): void
    {
        $bad = $this->latest('failed');
        $good = $this->latest();
        $good['run']['run_id'] = '0192f0a0-0000-7000-8000-000000000003';
        $good['run']['run_date'] = '2026-11-15';
        foreach (['created_at', 'updated_at', 'completed_at'] as $field) {
            $good['run'][$field] = '2026-11-15T00:35:00+00:00';
        }
        $http = new MockHttpClient([
            new MockResponse(json_encode($bad, \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($good, \JSON_THROW_ON_ERROR)),
        ]);
        $action = $this->action($http);
        $action->pull();
        self::assertSame('failed', $action->health());
        self::assertSame('failed', $action->health());
        $this->clock->modify('+1 day');
        $action->pull();

        self::assertSame('passed', $action->health());
        self::assertSame('passed', $action->health());
        self::assertCount(2, $this->logs->getRecords());
        self::assertTrue($this->logs->hasInfoThatContains('recovered'));
    }

    #[DataProvider('incompleteStates')]
    public function testProviderBudgetAndIncompleteStatesStayDistinct(string $status): void
    {
        $action = $this->action($this->http($this->latest($status)));
        $action->pull();
        self::assertSame($status, $action->health());
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteStates(): iterable
    {
        foreach (['unavailable', 'skipped_budget', 'incomplete', 'queued', 'running'] as $status) {
            yield $status => [$status];
        }
    }

    public function testExhaustedBudgetWarnsAndNeverReportsQualityPassed(): void
    {
        $action = $this->action($this->http($this->latest('skipped_budget')));
        $action->pull();
        self::assertSame('skipped_budget', $action->health());
        self::assertSame('skipped_budget', $action->health());
        self::assertTrue($this->logs->hasWarningRecords());
        self::assertFalse($this->logs->hasErrorRecords());
        self::assertCount(1, $this->logs->getRecords());
    }

    public function testRemoteDisabledAndContractErrorsCannotBecomePassed(): void
    {
        $action = $this->action($this->http(['enabled' => false, 'configuration_fingerprint' => null, 'run' => null]));
        self::assertSame('remote_disabled', $action->pull());
        self::assertSame('remote_disabled', $action->health());

        $action = $this->action($this->http(['enabled' => true, 'run' => 'private invalid body']));
        self::assertSame('contract_violation', $action->pull());
        self::assertSame('contract_violation', $action->health());
        foreach ($this->logs->getRecords() as $record) {
            self::assertStringNotContainsString('private invalid body', json_encode($record->context, \JSON_THROW_ON_ERROR));
        }
    }

    public function testFirstActivationWaitsWithoutInventingSuccessfulRun(): void
    {
        $action = $this->action($this->http(['enabled' => true, 'configuration_fingerprint' => self::FP, 'run' => null]));
        $action->pull();
        self::assertSame('waiting', $action->health());
        $this->clock->modify('+1 day');
        $action->pull();
        self::assertSame('missing', $action->health());
    }

    public function testIncompletePassedReportCannotTurnHealthGreen(): void
    {
        $latest = $this->latest();
        $latest['run']['report']['models'] = new \stdClass();
        $action = $this->action($this->http($latest));
        self::assertSame('updated', $action->pull());
        self::assertSame('incomplete', $action->health());
    }

    public function testFailureAlertContainsOnlySafeCaseAndMetricMetadata(): void
    {
        $latest = $this->latest('failed');
        $latest['run']['report']['models']['stub/primary']['datasets'] = ['source' => [
            'metrics' => ['category_exact_agreement' => ['numerator' => 0, 'denominator' => 1]],
            'cases' => [[
                'id' => 'sem-001', 'verdict' => 'needs_review',
                'metrics' => ['category_exact_agreement' => ['numerator' => 0, 'denominator' => 1]],
                'text' => 'PRIVATE_PAYMENT_TEXT', 'output' => ['amount' => 'PRIVATE_AMOUNT'],
            ]],
        ]];
        $action = $this->action($this->http($latest));
        self::assertSame('updated', $action->pull());
        self::assertSame('failed', $action->health());
        $context = $this->logs->getRecords()[0]->context;
        self::assertIsArray($context['failures']);
        self::assertContains('sem-001', array_column($context['failures'], 'case_id'));
        self::assertContains('category_exact_agreement', array_column($context['failures'], 'metric'));
        self::assertStringNotContainsString('PRIVATE_', json_encode($context, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('PRIVATE_', (string) $this->connection->fetchOne('SELECT report::text FROM ai_quality_run'));
    }

    public function testLatePostAcceptanceCannotDowngradeAlreadyPulledResult(): void
    {
        $pull = $this->action($this->http($this->latest()));
        $http = new MockHttpClient(static function () use ($pull): MockResponse {
            self::assertSame('updated', $pull->pull());

            return new MockResponse(json_encode([
                'contract_version' => 'v1', 'run_id' => self::RUN, 'status' => 'queued',
                'duplicate' => false, 'configuration_fingerprint' => self::FP,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 202]);
        });
        $action = $this->action($http);
        self::assertSame('accepted', $action->request());
        self::assertSame('passed', $action->health());
    }

    public function testOlderPullObservationDoesNotOverwriteNewerDailyResult(): void
    {
        $good = $this->action($this->http($this->latest()));
        $good->pull();
        $old = $this->latest('queued');
        $old['run']['updated_at'] = '2026-11-14T00:25:00+00:00';
        $action = $this->action($this->http($old));
        self::assertSame('updated', $action->pull());
        self::assertSame('passed', $action->health());
    }

    public function testLateResponseFromOldVersionCannotRestoreOldGreenFingerprint(): void
    {
        $old = $this->latest();
        $new = $old;
        $new['configuration_fingerprint'] = str_repeat('b', 64);
        $newAction = $this->action($this->http($new));
        $http = new MockHttpClient(function () use ($newAction, $old): MockResponse {
            $this->clock->modify('+1 second');
            self::assertSame('updated', $newAction->pull());

            return new MockResponse(json_encode($old, \JSON_THROW_ON_ERROR));
        });
        $action = $this->action($http);
        self::assertSame('updated', $action->pull());
        self::assertSame('version_changed', $action->health());
    }

    public function testLateFailedPollDoesNotEraseNewerSuccessfulContact(): void
    {
        $new = $this->action($this->http($this->latest()));
        $http = new MockHttpClient(static function () use ($new): never {
            self::assertSame('updated', $new->pull());
            throw new TransportException('late old request failed');
        });
        $old = $this->action($http);
        self::assertSame('unavailable', $old->pull());
        self::assertSame('passed', $old->health());
    }

    public function testAcceptanceSwitchesToNewMoscowDayAtFour(): void
    {
        $this->clock->modify('2026-11-15T00:45:00+00:00');
        $this->action($this->http($this->latest()))->pull();
        $today = $this->latest('running');
        $today['run']['run_id'] = '0192f0a0-0000-7000-8000-000000000003';
        $today['run']['run_date'] = '2026-11-15';
        $today['run']['updated_at'] = '2026-11-15T00:35:00+00:00';
        $action = $this->action($this->http($today));
        $action->pull();
        self::assertSame('passed', $action->health());
        $this->clock->modify('+15 minutes');
        self::assertSame('running', $action->health());
    }

    public function testChangedRemoteIdentityIsQuarantinedAndPreviousResultPreserved(): void
    {
        $this->action($this->http($this->latest()))->pull();
        $other = $this->latest();
        $other['run']['run_id'] = '0192f0a0-0000-7000-8000-000000000099';
        $action = $this->action($this->http($other));
        self::assertSame('contract_violation', $action->pull());
        self::assertSame('contract_violation', $action->health());
        self::assertSame(self::RUN, $this->connection->fetchOne('SELECT remote_id::text FROM ai_quality_run'));
    }

    private function action(MockHttpClient $http, bool $enabled = true): AiQualityAction
    {
        return new AiQualityAction(
            $this->connection,
            new AiServiceClient($http, new AiTaskV1Parser(), new Logger('http'), 'http://ai-service:8080', 'test-token'),
            new AiServiceAccess(true, 'http://ai-service:8080', 'test-token', $this->connection),
            $this->clock,
            new Logger('quality', [$this->logs]),
            $enabled,
        );
    }

    /** @param array<string, mixed> $value */
    private function http(array $value): MockHttpClient
    {
        return new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode($value, \JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function latest(string $status = 'passed'): array
    {
        $pending = \in_array($status, ['queued', 'running'], true);

        return ['enabled' => true, 'configuration_fingerprint' => self::FP, 'run' => [
            'contract_version' => 'v1', 'run_id' => self::RUN, 'suite' => 'payment_intake',
            'run_date' => '2026-11-14', 'trace_id' => self::TRACE, 'status' => $status, 'attempt' => 1,
            'configuration_fingerprint' => self::FP,
            'created_at' => '2026-11-14T00:25:00+00:00', 'updated_at' => '2026-11-14T00:35:00+00:00',
            'completed_at' => $pending ? null : '2026-11-14T00:35:00+00:00',
            'error_code' => 'passed' === $status || $pending ? null : 'eval_'.$status,
            'report' => $pending ? null : [
                'complete' => 'passed' === $status, 'network_calls' => 1, 'configuration_fingerprint' => self::FP,
                'configuration' => ['route' => ['models' => [['model' => 'stub/primary']]]],
                'models' => ['stub/primary' => [
                    'status' => $status, 'complete' => 'passed' === $status, 'network_calls' => 1,
                    'uncovered' => ['source' => 'passed' === $status ? 0 : 1],
                ]],
            ],
        ]];
    }
}
