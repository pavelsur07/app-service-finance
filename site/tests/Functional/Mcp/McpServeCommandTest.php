<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Протокол проверяется на живом процессе: только так видно, что STDOUT
 * не засорён ничем, кроме JSON-RPC, и что инструменты реально зарегистрированы.
 *
 * Данных компании тесты не касаются — команда проверяет лишь формат company-id,
 * поэтому отдельная БД для этих сценариев не нужна.
 */
final class McpServeCommandTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testInitializeAndToolsListOverRealProcess(): void
    {
        $responses = $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
            ['method' => 'notifications/initialized'],
            ['id' => 2, 'method' => 'tools/list'],
        ]);

        // Уведомление ответа не порождает — иначе ответов было бы три.
        self::assertCount(2, $responses);
        self::assertSame('2025-06-18', $responses[0]['result']['protocolVersion']);
        self::assertSame('vashfindir-cash', $responses[0]['result']['serverInfo']['name']);

        self::assertSame(
            ['cash_autorules_list', 'cash_categories_tree', 'cash_transactions_list'],
            $this->toolNames($responses[1]),
        );
    }

    public function testWriteToolsAppearOnlyWithAllowWrite(): void
    {
        $responses = $this->serve([['id' => 1, 'method' => 'tools/list']], allowWrite: true);

        self::assertSame(
            [
                'cash_autorule_upsert',
                'cash_autorules_list',
                'cash_categories_tree',
                'cash_category_upsert',
                'cash_transactions_list',
            ],
            $this->toolNames($responses[0]),
        );
    }

    public function testEveryToolAdvertisesObjectSchemaAndDescription(): void
    {
        $responses = $this->serve([['id' => 1, 'method' => 'tools/list']], allowWrite: true);

        foreach ($responses[0]['result']['tools'] as $tool) {
            self::assertNotSame('', $tool['description'], $tool['name'].': пустое описание');
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name'].': схема не объект');
        }
    }

    public function testUnknownToolIsRejectedWithoutKillingSession(): void
    {
        $responses = $this->serve([
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'no_such_tool']],
            ['id' => 2, 'method' => 'ping'],
        ]);

        self::assertSame(-32602, $responses[0]['error']['code']);
        // Сессия продолжает работать после отказа.
        self::assertSame([], $responses[1]['result']);
    }

    public function testInvalidCompanyIdExitsWithoutServing(): void
    {
        $process = $this->process('not-a-uuid', false);
        $process->setInput('{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n");
        $process->run();

        self::assertSame(2, $process->getExitCode(), 'Command::INVALID');
        self::assertSame('', trim($process->getOutput()));
        self::assertStringContainsString('UUID', $process->getErrorOutput());
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function serve(array $messages, bool $allowWrite = false): array
    {
        $input = '';
        foreach ($messages as $message) {
            $input .= json_encode($message + ['jsonrpc' => '2.0'], \JSON_THROW_ON_ERROR)."\n";
        }

        $process = $this->process(self::COMPANY_ID, $allowWrite);
        $process->setInput($input);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            'Процесс завершился с ошибкой: '.$process->getErrorOutput(),
        );

        $responses = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $responses[] = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        }

        return $responses;
    }

    private function process(string $companyId, bool $allowWrite): Process
    {
        $command = [\PHP_BINARY, 'bin/console', 'app:mcp:serve', '--company-id='.$companyId];
        if ($allowWrite) {
            $command[] = '--allow-write';
        }

        // SHELL_VERBOSITY=-1 из phpunit.xml иначе наследуется и глушит консольный вывод
        // дочернего процесса. Протокол это не задевает — он пишется в STDOUT напрямую,
        // но диагностику в STDERR так не увидеть.
        return new Process(
            $command,
            \dirname(__DIR__, 3),
            ['APP_ENV' => 'test', 'SHELL_VERBOSITY' => '0'],
            null,
            60.0,
        );
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<string>
     */
    private function toolNames(array $response): array
    {
        $names = array_column($response['result']['tools'], 'name');
        sort($names);

        return $names;
    }
}
