<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

/**
 * Память на процесс поверх любого источника учётных данных.
 *
 * Перепрос статусов ходит в Ozon по ОДНОМУ отправлению — до тысячи вызовов на
 * подключение, — и каждый означал бы запрос в БД и расшифровку одного и того
 * же секрета. Это ровно тот N+1, который правила проекта запрещают.
 *
 * Отдельный декоратор, а не поле в адаптере: адаптер над Facade остаётся
 * stateless и `readonly`, а память живёт там, где её видно и можно проверить
 * через интерфейс. Заодно кэш применим к любой другой реализации источника.
 *
 * Память живёт на процесс: CLI-команда читает ключ один раз за прогон, а
 * воркер — в пределах своего `--time-limit`. Заменённый ключ подхватывается
 * следующим процессом; держать его дольше жизни процесса нечему.
 */
final class CachingOzonCredentialProvider implements OzonCredentialProviderInterface
{
    /** @var array<string, array{api_key: string, client_id: ?string}> */
    private array $cache = [];

    public function __construct(private readonly OzonCredentialProviderInterface $inner)
    {
    }

    /**
     * @return array{api_key: string, client_id: ?string}
     */
    public function read(string $companyId, string $connectionRef): array
    {
        // Ключ — ПАРА: два кабинета одной компании ходят под разными ключами,
        // и общая запись означала бы запрос чужим ключом.
        $key = $companyId."\0".$connectionRef;

        return $this->cache[$key] ??= $this->inner->read($companyId, $connectionRef);
    }
}
