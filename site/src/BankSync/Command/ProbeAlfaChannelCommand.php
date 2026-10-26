<?php

declare(strict_types=1);

namespace App\BankSync\Command;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaApiClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTlsConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ручная проверка канала до Альфы после выкладки сертификата: запрос списка счетов без
 * токена. Любой HTTP-ответ банка (обычно 401) — TLS пройден: сервер принял наш
 * сертификат. Обрыв рукопожатия или `certificate required` — не пройден.
 */
#[AsCommand(name: 'bank-sync:alfa:channel-probe', description: 'Проверить mTLS-канал до API Альфы (песочница или прод)')]
final class ProbeAlfaChannelCommand extends Command
{
    private const TIMEOUT_SECONDS = 15;
    private const BASE_URLS = [
        'sandbox' => BankConnectionFormData::SANDBOX_URL,
        'production' => BankConnectionFormData::PRODUCTION_URL,
    ];

    public function __construct(
        private readonly AlfaTlsConfig $tlsConfig,
        #[Autowire(service: 'bank_sync.alfa.http_client')]
        private readonly HttpClientInterface $alfaHttpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('target', null, InputOption::VALUE_REQUIRED, 'sandbox или production', 'sandbox');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) $input->getOption('target');
        $baseUrl = self::BASE_URLS[$target] ?? null;
        if (null === $baseUrl) {
            $output->writeln('<error>Unknown target. Use --target=sandbox or --target=production</error>');

            return Command::INVALID;
        }
        $url = $baseUrl.AlfaApiClient::ACCOUNTS_PATH;

        try {
            // Та же проверка, что при создании клиента, — до запроса, с понятным текстом.
            $this->tlsConfig->httpOptions();
        } catch (BankChannelNotConfiguredException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        try {
            $response = $this->alfaHttpClient->request('GET', $url, ['timeout' => self::TIMEOUT_SECONDS]);
            $statusCode = $response->getStatusCode();
            $traceId = $response->getHeaders(false)['x-traceid'][0] ?? 'none';
        } catch (TransportExceptionInterface $exception) {
            $output->writeln(sprintf('<error>TLS channel to %s FAILED: %s</error>', $baseUrl, $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('TLS channel to %s OK: bank answered HTTP %d, X-TraceId %s.', $baseUrl, $statusCode, $traceId));

        return Command::SUCCESS;
    }
}
