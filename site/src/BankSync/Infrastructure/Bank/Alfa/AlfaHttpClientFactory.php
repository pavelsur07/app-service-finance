<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Exception\BankChannelNotConfiguredException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP-клиент Альфы (`bank_sync.alfa.http_client`) — отдельный от общего `http_client`:
 * сертификат и CA есть только у него. Не проходит через профайлер, куда попали бы
 * заголовки с токенами.
 *
 * Сервис ленивый: без настроенного канала приложение работает, падает только первый
 * запрос к Альфе.
 */
final readonly class AlfaHttpClientFactory
{
    public function __construct(private AlfaTlsConfig $config)
    {
    }

    /**
     * @throws BankChannelNotConfiguredException
     */
    public function create(): HttpClientInterface
    {
        return HttpClient::create($this->config->httpOptions());
    }
}
