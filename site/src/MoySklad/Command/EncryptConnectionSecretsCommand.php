<?php

declare(strict_types=1);

namespace App\MoySklad\Command;

use App\MoySklad\Application\Action\EncryptConnectionSecretsAction;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:moysklad:encrypt-secrets', description: 'Шифрование старых токенов МойСклад одной компании (dry-run по умолчанию).')]
final class EncryptConnectionSecretsCommand extends Command
{
    public function __construct(private readonly EncryptConnectionSecretsAction $action)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('company', null, InputOption::VALUE_REQUIRED, 'UUID компании (обязательно)');
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Зашифровать и очистить исходные токены');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getOption('company');
        if (!is_string($companyId) || !Uuid::isValid($companyId)) {
            $io->error('Укажите корректный UUID компании через --company.');

            return Command::INVALID;
        }
        $execute = (bool) $input->getOption('execute');
        try {
            $counts = ($this->action)($companyId, $execute);
        } catch (\Throwable) {
            // Exceptions can contain encrypted payloads or database parameters.
            $io->error('Шифрование остановлено: ошибка проверки или сохранения. Текущее подключение не изменено. Предыдущие подключения могли быть обработаны; повторный запуск безопасен.');

            return Command::FAILURE;
        }
        $io->success(sprintf('%s: проверено %d, требуют шифрования %d, зашифровано %d.', $execute ? 'EXECUTE' : 'DRY-RUN', $counts['scanned'], $counts['pending'], $counts['encrypted']));

        return Command::SUCCESS;
    }
}
