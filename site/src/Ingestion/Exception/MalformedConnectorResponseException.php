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
     * В лог этот payload не пишется: там разрешены только идентификаторы и
     * статусы, но не тела ответов внешних API.
     *
     * @var array<string, mixed>|null
     */
    private ?array $decodedPayload;

    /**
     * @param array<string, mixed>|null $decodedPayload
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?array $decodedPayload = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->decodedPayload = $decodedPayload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodedPayload(): ?array
    {
        return $this->decodedPayload;
    }
}
