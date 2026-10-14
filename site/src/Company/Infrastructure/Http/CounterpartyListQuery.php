<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Параметры списка «Справочники → Контрагенты», которые действия строки (архив, «Применить»)
 * несут в адресе формы, чтобы вернуть пользователя на ту же страницу с теми же фильтрами.
 * Только строковые значения из белого списка — остальное отбрасывается.
 */
final readonly class CounterpartyListQuery
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

        // Неверные page/limit не переносим — иначе редирект привёл бы на 422 списка.
        if (isset($query['page']) && false === filter_var($query['page'], \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            unset($query['page']);
        }
        if (isset($query['limit']) && false === filter_var($query['limit'], \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]])) {
            unset($query['limit']);
        }

        return $query;
    }
}
