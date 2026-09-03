<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

/**
 * Ответ источника нарушает контракт: не тот тип, нет обязательного поля.
 *
 * Отдельно от {@see ConnectorTransientException}, потому что ретраить такое
 * бессмысленно — повтор вернёт ту же поломанную структуру. И отдельно от
 * «пусто»: пустой список это валидный ответ, а отсутствующий ключ — нет.
 */
final class MalformedConnectorResponseException extends \RuntimeException
{
    /**
     * Разобранный payload ответа, если его вообще удалось разобрать.
     *
     * Нужен вызывающему, чтобы положить нарушивший контракт ответ в сырьё:
     * именно он и требуется как доказательство, а выброшенный он не объясняет
     * ничего. NULL означает, что разобрать было нечего — например, ответ не
     * был валидным JSON.
     *
     * Значение всегда JSON-ОБЪЕКТ, потому что строка сырья — объект на запись.
     * Список или скаляр клиент заворачивает в конверт с одним полем: они
     * нарушают контракт ровно так же, и терять их значило бы не создать сырья
     * вовсе — дефект интеграции стало бы не по чему разбирать.
     *
     * В лог этот payload не пишется: там разрешены только идентификаторы и
     * статусы, но не тела ответов внешних API.
     *
     * @var array<string, mixed>|null
     */
    private ?array $decodedPayload;

    /**
     * Относится ли нарушение ко ВСЕМУ эндпоинту, а не к одному объекту.
     *
     * Неожиданный HTTP-код — свойство эндпоинта: следующий заказ вернёт ровно
     * то же самое. Продолжать по заказам значило бы сделать сотни одинаковых
     * запросов и завершить прогон успехом при сломанном API. Нарушение формы
     * успешного ответа, наоборот, относится к конкретному объекту.
     */
    private bool $endpointWide;

    /**
     * @param array<string, mixed>|null $decodedPayload
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?array $decodedPayload = null,
        bool $endpointWide = false,
    ) {
        parent::__construct($message, $code, $previous);

        $this->decodedPayload = $decodedPayload;
        $this->endpointWide = $endpointWide;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodedPayload(): ?array
    {
        return $this->decodedPayload;
    }

    public function isEndpointWide(): bool
    {
        return $this->endpointWide;
    }
}
