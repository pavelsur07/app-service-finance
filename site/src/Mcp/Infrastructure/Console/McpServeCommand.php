<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Console;

use App\Mcp\Application\McpServer;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MCP-сервер поверх stdio. Внешних доступов нет: ни портов, ни HTTP.
 *
 * STDOUT принадлежит протоколу — туда пишет только send(). Всё остальное идёт в STDERR.
 */
#[AsCommand(
    name: 'app:mcp:serve',
    description: 'MCP-сервер (stdio) для данных ДДС одной компании',
)]
final class McpServeCommand extends Command
{
    public function __construct(
        private readonly McpServer $server,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'company-id',
                null,
                InputOption::VALUE_REQUIRED,
                'UUID компании. Все финансовые инструменты работают только с её данными.',
            )
            ->addOption(
                'allow-write',
                null,
                InputOption::VALUE_NONE,
                'Разрешить инструменты, изменяющие данные. По умолчанию сервер только на чтение.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $companyId = $input->getOption('company-id');
        if (!\is_string($companyId) || !Uuid::isValid($companyId)) {
            $stderr->writeln('<error>Укажите --company-id в формате UUID.</error>');

            return Command::INVALID;
        }

        $allowWrite = true === $input->getOption('allow-write');

        $stderr->writeln(sprintf(
            '<info>%s %s</info>: company=%s, режим=%s',
            McpServer::SERVER_NAME,
            McpServer::SERVER_VERSION,
            $companyId,
            $allowWrite ? 'чтение и запись' : 'только чтение',
        ));

        while (false !== ($line = fgets(\STDIN))) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $response = $this->server->handleLine($line, $companyId, $allowWrite);
            if (null !== $response) {
                fwrite(\STDOUT, $response."\n");
                fflush(\STDOUT);
            }
        }

        return Command::SUCCESS;
    }
}
