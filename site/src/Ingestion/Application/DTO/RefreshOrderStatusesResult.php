<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Итог одного прогона перепроса статусов.
 *
 * Счётчики разделены намеренно. «Спрошено» (`requested`) — сколько заказов
 * дошло до запроса, включая те, что вернулись 404 или без статуса. «Получено
 * наблюдений» (`observed`) — сколько из них дали пригодный статус. «Изменилось»
 * (`changed`) — сколько статусов действительно сдвинулось. Один общий счётчик
 * скрыл бы и настоящую нагрузку, и случай, когда опрос идёт, а статусы почему-то
 * никогда не меняются. По той же причине разведены «маркетплейс не
 * знает такого заказа» (`missing`) и «ответ нарушает контракт» (`invalid`):
 * первое — норма, второе — дефект интеграции. И отказ авторизации отделён от
 * прочих сбоев подключения: 429 и таймаут проходят сами, протухший ключ ждёт
 * человека.
 */
final readonly class RefreshOrderStatusesResult
{
    public function __construct(
        public int $requested = 0,
        public int $observed = 0,
        public int $changed = 0,
        public int $missing = 0,
        public int $invalid = 0,
        public int $stopped = 0,
        public int $failedConnections = 0,
        public int $authFailedConnections = 0,
    ) {
    }

    public function with(
        int $requested = 0,
        int $observed = 0,
        int $changed = 0,
        int $missing = 0,
        int $invalid = 0,
        int $stopped = 0,
        int $failedConnections = 0,
        int $authFailedConnections = 0,
    ): self {
        return new self(
            $this->requested + $requested,
            $this->observed + $observed,
            $this->changed + $changed,
            $this->missing + $missing,
            $this->invalid + $invalid,
            $this->stopped + $stopped,
            $this->failedConnections + $failedConnections,
            $this->authFailedConnections + $authFailedConnections,
        );
    }
}
