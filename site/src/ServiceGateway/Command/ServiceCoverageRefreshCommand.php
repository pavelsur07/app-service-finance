<?php

declare(strict_types=1);

namespace App\ServiceGateway\Command;

use App\ServiceGateway\Application\Action\RefreshServiceCoverageAction;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\ServiceCoverageClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Фоновое обновление витрины покрытия сервисов (cron каждые 10 минут). Страница читает
 * только витрину (docs/architecture/service-gateway.md §2.6).
 */
#[AsCommand(
    name: 'app:service-gateway:coverage:refresh',
    description: 'Refreshes the service coverage read model from GET /internal/coverage of each service',
)]
final class ServiceCoverageRefreshCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly ServiceCoverageClientInterface $client,
        private readonly RefreshServiceCoverageAction $refresh,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = $this->client->service();
        if (!$this->client->isEnabled()) {
            $this->logger->info('Service coverage refresh is disabled.', ['service' => $service]);
            $output->writeln(sprintf('%s: disabled (base URL or token is empty)', $service));

            return self::SUCCESS;
        }
        if (!$this->lock()) {
            $output->writeln(sprintf('%s: another refresh is running, skipping', $service));

            return self::SUCCESS;
        }

        try {
            $this->logger->info('Service coverage refresh started.', ['service' => $service]);
            $result = ($this->refresh)($this->client, $this->clock->now());
            $this->logger->info('Service coverage refresh finished.', [
                'service' => $service,
                'rows' => $result->rows,
                'cabinetsTotal' => $result->cabinetsTotal,
            ]);
            $output->writeln(sprintf('%s: %d rows, %d cabinets', $service, $result->rows, $result->cabinetsTotal));

            return self::SUCCESS;
        } catch (ServiceUnavailableException $e) {
            // Ожидаемо: сервис перезапускается или недоступен — повтор через 10 минут.
            // Exit 0: supercronic отправляет любой ненулевой exit в GlitchTip как ошибку.
            $this->logger->warning('Service coverage refresh skipped: service unavailable.', ['service' => $service, 'error' => $e->getMessage()]);
            $output->writeln(sprintf('%s: unavailable, read model kept', $service));

            return self::SUCCESS;
        } catch (ContractViolationException $e) {
            // Расхождение контракта — баг одной из сторон, сам не пройдёт.
            $this->logger->error('Service coverage refresh rejected: contract violation.', ['service' => $service, 'error' => $e->getMessage()]);
            $output->writeln(sprintf('%s: contract violation, read model kept', $service));

            return self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
