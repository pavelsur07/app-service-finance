<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Http;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Последний вид списка раздела «Документы ОПиУ» в сессии: вкладка, фильтры, страница.
 *
 * Источник правды — адрес списка: фильтры приходят из строки запроса, ссылкой можно
 * поделиться, F5 и «Назад» работают. Сессия только помнит последний открытый адрес,
 * чтобы входы в раздел (меню, хлебные крошки, «Отмена», переход после сохранения)
 * вели не на сброшенный журнал, а туда, где пользователь его оставил. Сам список
 * сессию не читает: «Сбросить всё» по-прежнему даёт пустой журнал.
 *
 * Ключ — пользователь и компания: фильтры ссылаются на статьи и направления своей
 * компании, после смены компании чужие идентификаторы не применяются; сотрудник,
 * вошедший в том же браузере, не получает журнал предыдущего. Поэтому сессия, а не
 * localStorage: тот живёт в браузере и переживает выход из учётной записи.
 */
final readonly class DocumentListState
{
    /** Вкладки, которые помнятся; у «Удалённых» фильтров нет. */
    public const ROUTES = ['document_index', 'document_operation_index'];

    /** Параметры списка, см. DocumentListDTO::fromQuery(); прочее из адреса не сохраняется. */
    private const KEYS = [
        'page', 'limit', 'dateFrom', 'dateTo', 'type', 'status', 'number', 'counterpartyId', 'q',
        'projectDirection', 'responsibilityCenter', 'direction', 'category', 'amountFrom', 'amountTo',
    ];

    /** Длиннее значения поля поиска не бывают; ограничение держит сессию маленькой. */
    private const MAX_VALUE_LENGTH = 255;

    public function __construct(
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public function remember(string $companyId, string $route, InputBag $query): void
    {
        $params = [];
        foreach (self::KEYS as $key) {
            $value = $query->all()[$key] ?? null;
            if (\is_string($value) && '' !== trim($value) && mb_strlen($value) <= self::MAX_VALUE_LENGTH) {
                $params[$key] = $value;
            }
        }

        $this->requestStack->getSession()->set($this->sessionKey($companyId), ['route' => $route, 'query' => $params]);
    }

    /**
     * @return array{route: string, query: array<string, string>}
     */
    public function recall(string $companyId): array
    {
        $saved = $this->requestStack->getSession()->get($this->sessionKey($companyId));
        $route = \is_array($saved) ? ($saved['route'] ?? null) : null;
        $savedQuery = \is_array($saved) ? ($saved['query'] ?? null) : null;
        if (!\is_string($route) || !\in_array($route, self::ROUTES, true) || !\is_array($savedQuery)) {
            return ['route' => self::ROUTES[0], 'query' => []];
        }

        $query = [];
        foreach (self::KEYS as $key) {
            if (\is_string($savedQuery[$key] ?? null)) {
                $query[$key] = $savedQuery[$key];
            }
        }

        return ['route' => $route, 'query' => $query];
    }

    private function sessionKey(string $companyId): string
    {
        return 'finance.document_list.'.$this->security->getUser()?->getUserIdentifier().'.'.$companyId;
    }
}
