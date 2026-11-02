<?php

declare(strict_types=1);

namespace App\Billing\Command;

use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Message\ApplyYooKassaPaymentMessage;
use App\Billing\Repository\BillingCheckoutRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cron: сверка незавершённых онлайн-оплат с ЮKassa — на случай потерянного уведомления
 * и закрытой до возврата вкладки. Ставит проверку каждой попытки в очередь `async_sync`;
 * повтор безопасен (`ApplyYooKassaPaymentAction` идемпотентен). Возраст не ограничен: после
 * простоя или выключенных ключей успешный платёж всё равно дойдёт; неоплаченный ЮKassa
 * сама переводит в `canceled`, и попытка выходит из сверки.
 */
#[AsCommand(name: 'app:billing:yookassa-sync', description: 'Сверить незавершённые онлайн-оплаты с ЮKassa')]
final class YooKassaSyncCommand extends Command
{
    use LockableTrait;

    /** Не трогаем совсем свежие: клиент ещё на странице оплаты. */
    private const MIN_AGE = '-5 minutes';
    private const LIMIT = 200;

    public function __construct(
        private readonly YooKassaConfig $config,
        private readonly BillingCheckoutRepository $checkouts,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('Онлайн-оплата выключена.');

            return Command::SUCCESS;
        }
        if (!$this->lock()) {
            $output->writeln('Уже выполняется.');

            return Command::SUCCESS;
        }

        try {
            $now = $this->clock->now();
            $pending = $this->checkouts->findPendingWithProviderPayment($now->modify(self::MIN_AGE), self::LIMIT);
            foreach ($pending as $checkout) {
                $this->bus->dispatch(new ApplyYooKassaPaymentMessage((string) $checkout->getProviderPaymentId()));
            }

            if ([] !== $pending) {
                $this->logger->info('Billing: сверка ЮKassa — проверки поставлены', ['count' => \count($pending)]);
            }
            $output->writeln(sprintf('Поставлено проверок: %d.', \count($pending)));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
