<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Параметры списка «Справочники → Контрагенты», которые действия строки (архив, «Применить»)
 * несут в адресе формы, чтобы вернуть пользователя на ту же страницу с теми же фильтрами.
 * Только строковые значения из белого списка — остальное отбрасывается.
 */
final class CounterpartyListQuery
{
    private const KEYS = ['q', 'type', 'show_archived', 'limit', 'page'];

    /**
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        $parameters = $request->query->all();
        $query = [];
        foreach (self::KEYS as $key) {
            if (isset($parameters[$key]) && \is_string($parameters[$key]) && '' !== $parameters[$key]) {
                $query[$key] = $parameters[$key];
            }
        }

        return $query;
    }
}
