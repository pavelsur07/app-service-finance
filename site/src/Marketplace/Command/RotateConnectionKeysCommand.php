<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Shared\Command\RotateEncryptionKeysCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Прежнее имя ротации api_key маркетплейсов: то же, что
 * `app:security:rotate-keys --module=marketplace`. Удаляется после первой смены ключа
 * по общей инструкции (docs/maintenance/encryption-key-rotation.md).
 */
#[AsCommand(
    name: 'app:marketplace:rotate-connection-keys',
    description: 'Устарела: app:security:rotate-keys --module=marketplace (dry-run по умолчанию).',
)]
final class RotateConnectionKeysCommand extends Command
{
    public function __construct(
        private readonly RotateEncryptionKeysCommand $rotateKeys,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Выполнить ротацию (без флага — только отчёт)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arguments = ['--module' => ['marketplace']];
        if ($input->getOption('execute')) {
            $arguments['--execute'] = true;
        }

        return $this->rotateKeys->run(new ArrayInput($arguments), $output);
    }
}
