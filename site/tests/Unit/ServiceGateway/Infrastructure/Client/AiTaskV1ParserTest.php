<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Infrastructure\Client\AiTaskV1Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Фикстуры — копии services/_contracts/ai-task/v1/examples; совпадение копий
 * проверяет тест контракта ai-service.
 */
final class AiTaskV1ParserTest extends TestCase
{
    public static function fixture(string $name): \stdClass
    {
        $raw = file_get_contents(__DIR__.'/../../../../Fixtures/ServiceGateway/ai-task-v1/'.$name.'.json');
        self::assertIsString($raw);
        $data = json_decode($raw, false, 64, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $data);

        return $data;
    }

    public function testParsesResultsPageExample(): void
    {
        $page = (new AiTaskV1Parser())->parseResultsPage(self::fixture('results_page'));

        self::assertSame('2', $page->nextCursor);
        self::assertCount(2, $page->items);
        [$accepted, $failed] = $page->items;
        self::assertSame(AiResultStatus::ACCEPTED, $accepted->status);
        self::assertSame(['text' => 'проверка связи'], $accepted->output);
        self::assertSame(1.0, $accepted->confidence);
        self::assertSame(['stub/echo'], $accepted->models);
        self::assertSame('echo/v1', $accepted->promptVersion);
        self::assertSame('echo_matches_input', $accepted->checks[0]->name);
        self::assertSame('block', $accepted->checks[0]->severity);
        self::assertSame(AiResultStatus::FAILED, $failed->status);
        self::assertNull($failed->output);
        self::assertSame('llm_unavailable', $failed->errorCode);
    }

    public function testParsesAcceptedExample(): void
    {
        $accepted = (new AiTaskV1Parser())->parseAccepted(self::fixture('task_accepted'));

        self::assertSame('0192f0a0-0000-7000-8000-000000000001', $accepted->taskId);
        self::assertSame('queued', $accepted->status);
        self::assertFalse($accepted->duplicate);
    }

    /**
     * @return iterable<string, array{callable(\stdClass): mixed, string}>
     */
    public static function brokenResults(): iterable
    {
        yield 'unknown status' => [static fn (\stdClass $r) => $r->status = 'maybe', 'status'];
        yield 'extra key' => [static fn (\stdClass $r) => $r->extra = 1, 'unexpected extra'];
        yield 'missing key' => [static function (\stdClass $r): void { unset($r->usage); }, 'missing usage'];
        yield 'output array' => [static fn (\stdClass $r) => $r->output = [], 'output'];
        yield 'confidence above 1' => [static fn (\stdClass $r) => $r->confidence = 1.5, 'confidence'];
        yield 'confidence as string' => [static fn (\stdClass $r) => $r->confidence = '0.9', 'confidence'];
        yield 'bad task id' => [static fn (\stdClass $r) => $r->task_id = 'nope', 'task_id'];
        yield 'task id in braces' => [static fn (\stdClass $r) => $r->task_id = '{0192f0a0-0000-7000-8000-000000000001}', 'task_id'];
        yield 'company id as urn' => [static fn (\stdClass $r) => $r->company_id = 'urn:uuid:0192f0a0-0000-7000-8000-0000000000c1', 'company_id'];
        yield 'bad model' => [static fn (\stdClass $r) => $r->models = ['no-slash'], 'models[0]'];
        yield 'negative tokens' => [static fn (\stdClass $r) => $r->usage->input_tokens = -1, 'input_tokens'];
        yield 'bad date' => [static fn (\stdClass $r) => $r->completed_at = '2026-02-30T00:00:00Z', 'completed_at'];
        yield 'bad severity' => [static fn (\stdClass $r) => $r->checks[0]->severity = 'fatal', 'severity'];
    }

    /**
     * @param callable(\stdClass): mixed $break
     */
    #[DataProvider('brokenResults')]
    public function testSetsAsideResultViolatingContractWithoutRejectingPage(callable $break, string $where): void
    {
        $page = self::fixture('results_page');
        self::assertIsArray($page->items);
        $total = \count($page->items);
        $item = $page->items[0];
        self::assertInstanceOf(\stdClass::class, $item);
        $break($item);

        $parsed = (new AiTaskV1Parser())->parseResultsPage($page);

        self::assertCount($total - 1, $parsed->items);
        self::assertCount(1, $parsed->invalid);
        self::assertStringContainsString($where, $parsed->invalid[0]);
    }

    public function testRejectsPageEnvelopeViolation(): void
    {
        $page = self::fixture('results_page');
        $page->items = new \stdClass();

        $this->expectException(ContractViolationException::class);
        (new AiTaskV1Parser())->parseResultsPage($page);
    }

    public function testRejectsNonNumericCursor(): void
    {
        $page = self::fixture('results_page');
        $page->next_cursor = 'abc';

        $this->expectException(ContractViolationException::class);
        (new AiTaskV1Parser())->parseResultsPage($page);
    }
}
