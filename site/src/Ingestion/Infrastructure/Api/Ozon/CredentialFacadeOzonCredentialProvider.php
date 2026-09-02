<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Facade\CredentialFacade;

/**
 * Не `readonly`, потому что запоминает прочитанное.
 *
 * Перепрос статусов ходит в Ozon по одному отправлению — до тысячи вызовов на
 * подключение, — и каждый из них означал бы запрос в БД и расшифровку одного и
 * того же секрета. Это ровно тот N+1, который правила проекта запрещают.
 *
 * Память живёт на процесс: CLI-команда читает ключ один раз за прогон, а
 * воркер — в пределах своего `--time-limit`. Заменённый ключ подхватывается
 * следующим процессом; держать его дольше жизни процесса нечему.
 */
final class CredentialFacadeOzonCredentialProvider implements OzonCredentialProviderInterface
{
    /** @var array<string, array{api_key: string, client_id: ?string}> */
    private array $cache = [];

    public function __construct(private readonly CredentialFacade $credentialFacade)
    {
    }

    /**
     * @return array{api_key: string, client_id: ?string}
     */
    public function read(string $companyId, string $connectionRef): array
    {
        $key = $companyId."\0".$connectionRef;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        /** @var array{api_key: string, client_id: ?string} $payload */
        $payload = $this->credentialFacade->read($companyId, $connectionRef);

        return $this->cache[$key] = $payload;
    }
}
