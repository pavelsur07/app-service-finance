<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Infrastructure\Client\AiEvalV1Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiEvalV1ParserTest extends TestCase
{
    public static function fixture(string $name): \stdClass
    {
        $raw = file_get_contents(__DIR__.'/../../../../Fixtures/ServiceGateway/ai-eval-run-v1/'.$name.'.json');
        self::assertIsString($raw);
        $data = json_decode($raw, false, 64, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $data);

        return $data;
    }

    public function testParsesWireFixturesAndProjectsOnlySafeEvidence(): void
    {
        $parser = new AiEvalV1Parser();
        $accepted = $parser->parseAccepted(self::fixture('accepted'));
        self::assertSame('queued', $accepted->status);
        self::assertFalse($accepted->duplicate);
        self::assertSame(str_repeat('a', 64), $accepted->configurationFingerprint);
        $run = $parser->parseRun(self::fixture('run'));
        self::assertSame('2026-11-14', $run->runDate->format('Y-m-d'));
        self::assertSame('2026-11-14T00:25:00+00:00', $run->createdAt->format(\DATE_ATOM));
        self::assertSame('123456', $run->updatedAt->format('u'));
        self::assertInstanceOf(\DateTimeImmutable::class, $run->completedAt);
        self::assertTrue($run->isPassing());
        self::assertNotNull($run->report);
        self::assertSame(['synthetic/first', 'synthetic/second'], $run->report['expected_models']);
        self::assertSame(['regression' => 0, 'holdout' => 0], $run->report['models']['synthetic/first']['uncovered']);
        self::assertStringNotContainsString('RAW_', json_encode($run, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('datasets', json_encode($run, \JSON_THROW_ON_ERROR));
        $latest = $parser->parseLatest(self::fixture('latest'));
        self::assertTrue($latest->enabled);
        self::assertNotNull($latest->run);
        self::assertTrue($latest->run->isPassing());
        $disabled = $parser->parseLatest(self::fixture('disabled'));
        self::assertFalse($disabled->enabled);
        self::assertNull($disabled->run);
        self::assertNull($disabled->configurationFingerprint);
    }

    #[DataProvider('invalidRuns')]
    public function testRejectsInvalidWireWithoutEchoingRawValues(callable $change): void
    {
        $wire = self::fixture('run');
        $change($wire);
        try {
            (new AiEvalV1Parser())->parseRun($wire);
            self::fail('Invalid wire accepted');
        } catch (ContractViolationException $e) {
            self::assertStringContainsString('ai-eval-run/v1', $e->getMessage());
            self::assertStringNotContainsString('RAW_', $e->getMessage());
        }
    }

    /** @return iterable<string, array{callable(\stdClass): mixed}> */
    public static function invalidRuns(): iterable
    {
        yield 'unknown version' => [static fn ($r) => $r->contract_version = 'v2'];
        yield 'unknown status' => [static fn ($r) => $r->status = 'RAW_STATUS'];
        yield 'uuid' => [static fn ($r) => $r->run_id = 'RAW_UUID'];
        yield 'suite' => [static fn ($r) => $r->suite = 'other'];
        yield 'trace' => [static fn ($r) => $r->trace_id = 'invalid'];
        yield 'missing field' => [static function ($r): void { unset($r->attempt); }];
        yield 'unknown field' => [static fn ($r) => $r->RAW_KEY = 'RAW_BODY'];
        yield 'negative attempt' => [static fn ($r) => $r->attempt = -1];
        yield 'string attempt' => [static fn ($r) => $r->attempt = '1'];
        yield 'invalid hash' => [static fn ($r) => $r->configuration_fingerprint = str_repeat('A', 64)];
        yield 'invalid run date' => [static fn ($r) => $r->run_date = '2026-02-30'];
        yield 'date with suffix' => [static fn ($r) => $r->run_date = '2026-11-14Z'];
        yield 'invalid datetime' => [static fn ($r) => $r->updated_at = '2026-02-30T00:00:00Z'];
        yield 'timezone missing' => [static fn ($r) => $r->created_at = '2026-11-14T00:00:00'];
        yield 'invalid timezone offset' => [static fn ($r) => $r->created_at = '2026-11-14T00:00:00+99:99'];
        yield 'completed type' => [static fn ($r) => $r->completed_at = 1];
        yield 'error type' => [static fn ($r) => $r->error_code = []];
        yield 'report array' => [static fn ($r) => $r->report = []];
        yield 'report complete type' => [static fn ($r) => $r->report->complete = 'true'];
        yield 'network calls negative' => [static fn ($r) => $r->report->network_calls = -1];
        yield 'models array' => [static fn ($r) => $r->report->models = []];
        yield 'model status unknown' => [static fn ($r) => $r->report->models->{'synthetic/first'}->status = 'other'];
        yield 'uncovered not count' => [static fn ($r) => $r->report->models->{'synthetic/first'}->uncovered->holdout = '0'];
        yield 'reviewed version too long' => [static fn ($r) => $r->report->models->{'synthetic/first'}->uncovered->{'reviewed:'.str_repeat('a', 65)} = 0];
        yield 'reviewed version uppercase' => [static fn ($r) => $r->report->models->{'synthetic/first'}->uncovered->{'reviewed:Version1'} = 0];
        yield 'reviewed version invalid first character' => [static fn ($r) => $r->report->models->{'synthetic/first'}->uncovered->{'reviewed:.version'} = 0];
        yield 'reviewed version path separator' => [static fn ($r) => $r->report->models->{'synthetic/first'}->uncovered->{'reviewed:2026/11'} = 0];
    }

    #[DataProvider('unprovenPasses')]
    public function testIncompleteEvidenceNeverBecomesPassing(callable $change): void
    {
        $wire = self::fixture('run');
        $change($wire);
        self::assertFalse((new AiEvalV1Parser())->parseRun($wire)->isPassing());
    }

    /** @return iterable<string, array{callable(\stdClass): mixed}> */
    public static function unprovenPasses(): iterable
    {
        yield 'null report' => [static fn ($r) => $r->report = null];
        yield 'empty report' => [static fn ($r) => $r->report = new \stdClass()];
        yield 'incomplete report' => [static fn ($r) => $r->report->complete = false];
        yield 'zero calls' => [static fn ($r) => $r->report->network_calls = 0];
        yield 'unknown calls' => [static fn ($r) => $r->report->network_calls = null];
        yield 'wrong fingerprint' => [static fn ($r) => $r->report->configuration_fingerprint = str_repeat('b', 64)];
        yield 'missing fingerprint' => [static function ($r): void { unset($r->report->configuration_fingerprint); }];
        yield 'no models' => [static fn ($r) => $r->report->models = new \stdClass()];
        yield 'missing model' => [static function ($r): void { unset($r->report->models->{'synthetic/second'}); }];
        yield 'missing route evidence' => [static function ($r): void { unset($r->report->configuration); }];
        yield 'model failed' => [static fn ($r) => $r->report->models->{'synthetic/first'}->status = 'failed'];
        yield 'model incomplete' => [static fn ($r) => $r->report->models->{'synthetic/first'}->complete = false];
        yield 'uncovered case' => [static fn ($r) => $r->report->models->{'synthetic/first'}->uncovered->holdout = 1];
        yield 'uncovered missing' => [static function ($r): void { unset($r->report->models->{'synthetic/first'}->uncovered); }];
        yield 'uncovered empty' => [static fn ($r) => $r->report->models->{'synthetic/first'}->uncovered = new \stdClass()];
        yield 'model calls missing' => [static function ($r): void { unset($r->report->models->{'synthetic/first'}->network_calls); }];
        yield 'not passed' => [static fn ($r) => $r->status = 'running'];
        yield 'never claimed' => [static fn ($r) => $r->attempt = 0];
        yield 'not completed' => [static fn ($r) => $r->completed_at = null];
    }

    public function testLatestAndAcceptedRejectBadEnvelopeTypes(): void
    {
        $parser = new AiEvalV1Parser();
        foreach ([['accepted', 'duplicate', 1], ['latest', 'enabled', 'true'], ['latest', 'configuration_fingerprint', 'bad']] as [$fixture, $key, $value]) {
            $wire = self::fixture($fixture);
            $wire->{$key} = $value;
            try {
                'latest' === $fixture ? $parser->parseLatest($wire) : $parser->parseAccepted($wire);
                self::fail('Invalid envelope accepted');
            } catch (ContractViolationException $e) {
                self::assertStringContainsString('ai-eval-run/v1', $e->getMessage());
            }
        }
    }

    public function testAllPublishedStatesAndNullableFieldsRemainRepresentable(): void
    {
        $parser = new AiEvalV1Parser();
        foreach (['queued', 'running', 'passed', 'failed', 'unavailable', 'incomplete', 'skipped_budget'] as $status) {
            $wire = self::fixture('run');
            $wire->status = $status;
            $wire->completed_at = null;
            $wire->report = null;
            $wire->error_code = 'eval_'.$status;
            $run = $parser->parseRun($wire);
            self::assertSame($status, $run->status);
            self::assertNull($run->completedAt);
            self::assertNull($run->report);
            self::assertFalse($run->isPassing());
            $accepted = self::fixture('accepted');
            $accepted->status = $status;
            self::assertSame($status, $parser->parseAccepted($accepted)->status);
        }
    }

    public function testReviewedVersionDatasetCoverageRemainsPassing(): void
    {
        $wire = self::fixture('run');
        foreach ($wire->report->models as $model) {
            $model->uncovered->{'reviewed:2026-11-v1'} = 0;
        }
        try {
            $run = (new AiEvalV1Parser())->parseRun($wire);
        } catch (ContractViolationException) {
            self::fail('Published reviewed dataset version must be accepted');
        }

        self::assertTrue($run->isPassing());
        self::assertNotNull($run->report);
        self::assertSame(0, $run->report['models']['synthetic/first']['uncovered']['reviewed:2026-11-v1']);
    }

    public function testProjectsOnlySafeFailedCaseAndMetricIdentifiers(): void
    {
        $wire = self::fixture('run');
        $wire->report->models->{'synthetic/first'}->datasets = (object) [
            'reviewed:2026-11-v1' => (object) [
                'metrics' => (object) [
                    'category' => (object) ['numerator' => 0, 'denominator' => 1, 'details' => 'RAW_GOLD_SENTINEL'],
                    'direction' => (object) ['numerator' => 1, 'denominator' => 1],
                    'overall_accuracy' => null,
                ],
                'cases' => [(object) [
                    'id' => 'sem-001', 'verdict' => 'rejected',
                    'metrics' => (object) ['category' => (object) ['numerator' => 0, 'denominator' => 1]],
                    'diagnostics' => ['RAW_DIAGNOSTICS_SENTINEL'], 'text' => 'RAW_INPUT_SENTINEL',
                    'amount' => 'RAW_AMOUNT_SENTINEL', 'evidence' => 'RAW_MODEL_SENTINEL',
                ], (object) [
                    'id' => 'sem-002', 'verdict' => 'accepted',
                    'metrics' => (object) ['direction' => (object) ['numerator' => 1, 'denominator' => 1]],
                ]],
            ],
        ];
        $run = (new AiEvalV1Parser())->parseRun($wire);
        self::assertNotNull($run->report);
        $failures = $run->report['models']['synthetic/first']['failures'] ?? [];
        self::assertSame([
            ['dataset' => 'reviewed:2026-11-v1', 'metric' => 'category', 'numerator' => 0, 'denominator' => 1],
            ['dataset' => 'reviewed:2026-11-v1', 'case_id' => 'sem-001', 'verdict' => 'rejected'],
            ['dataset' => 'reviewed:2026-11-v1', 'case_id' => 'sem-001', 'metric' => 'category', 'numerator' => 0, 'denominator' => 1],
        ], $failures);
        self::assertStringNotContainsString('RAW_', json_encode($run->report, \JSON_THROW_ON_ERROR));
        self::assertFalse($run->isPassing(), 'Contradictory passed report with failed metrics must not be green');
    }

    public function testBoundsFailureProjectionToOneHundredEntriesPerModel(): void
    {
        $wire = self::fixture('run');
        $cases = [];
        for ($i = 0; $i < 105; ++$i) {
            $cases[] = (object) ['id' => 'reviewed-'.$i, 'verdict' => 'failed', 'details' => 'RAW_DETAILS_SENTINEL'];
        }
        $wire->report->models->{'synthetic/first'}->datasets = (object) ['semantic' => (object) ['cases' => $cases]];
        $run = (new AiEvalV1Parser())->parseRun($wire);
        self::assertNotNull($run->report);
        $failures = $run->report['models']['synthetic/first']['failures'] ?? [];
        self::assertCount(100, $failures);
        self::assertSame(['dataset' => 'semantic', 'case_id' => 'reviewed-99', 'verdict' => 'failed'], $failures[99]);
        self::assertStringNotContainsString('RAW_', json_encode($failures, \JSON_THROW_ON_ERROR));
    }
}
