<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\RefreshOrderStatusesAction;
use App\Ingestion\Application\Command\RefreshOrderStatusesCommand as RefreshOrderStatusesApplicationCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:orders:refresh-statuses',
    description: 'Re-polls marketplace statuses of non-terminal orders.',
)]
final class RefreshOrderStatusesCommand extends Command
{
    use LockableTrait;

    private const DEFAULT_DAYS = 30;
    private const DEFAULT_LIMIT = 200;

    public function __construct(private readonly RefreshOrderStatusesAction $action)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Order age window to keep polling.', self::DEFAULT_DAYS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum orders polled per connection.', self::DEFAULT_LIMIT)
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Optional company UUID filter.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Перепрос ходит во внешние API и двигает статусы: два одновременных
        // прогона удвоили бы нагрузку и подрались бы за одни и те же заказы.
        if (!$this->lock()) {
            $io->warning('Command is already running in another process.');

            return Command::SUCCESS;
        }

        try {
            $command = new RefreshOrderStatusesApplicationCommand(
                days: $this->positiveInt($input, 'days', 365),
                limitPerConnection: $this->positiveInt($input, 'limit', 1000),
                companyId: $this->companyId($input),
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            $this->release();

            return Command::INVALID;
        }

        try {
            $result = ($this->action)($command);
        } finally {
            $this->release();
        }

        $io->success(sprintf(
            'Polled %d orders (changed: %d, not returned: %d, stopped: %d, failed connections: %d).',
            $result->polled,
            $result->changed,
            $result->missing,
            $result->stopped,
            $result->failedConnections,
        ));

        return Command::SUCCESS;
    }

    /**
     * Строгий разбор: «0», «abc» и «-1» обязаны быть ошибкой ввода, а не
     * молча превращаться в ноль и делать прогон пустым.
     */
    private function positiveInt(InputInterface $input, string $option, int $max): int
    {
        $value = (string) $input->getOption($option);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from 1 to %d.', $option, $max));
        }

        $parsed = (int) $value;
        if ($parsed < 1 || $parsed > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from 1 to %d.', $option, $max));
        }

        return $parsed;
    }

    private function companyId(InputInterface $input): ?string
    {
        $value = trim((string) $input->getOption('company-id'));
        if ('' === $value) {
            return null;
        }

        Assert::uuid($value, 'Invalid --company-id UUID.');

        return $value;
    }
}
