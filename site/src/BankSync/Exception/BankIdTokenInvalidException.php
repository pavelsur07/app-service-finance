<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * `id_token` из ответа банка на обмен кода не прошёл проверку: подпись, издатель,
 * аудитория, срок или `nonce`. Токены этого входа не сохраняются. Либо подделка, либо
 * банк сменил ключ подписи, а у нас старый сертификат, — разбирает человек.
 *
 * `reason` — машинная причина (`signature`, `audience`…); ни токена, ни claims в
 * сообщении нет.
 */
final class BankIdTokenInvalidException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('Bank id_token is invalid: %s.', $reason));
    }
}
