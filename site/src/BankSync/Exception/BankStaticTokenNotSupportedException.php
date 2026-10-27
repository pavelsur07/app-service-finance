<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Банк не подключается токеном клиента (у Альфы — только вход через Alfa ID), а форма
 * пришла с токеном или сменой среды.
 */
final class BankStaticTokenNotSupportedException extends \RuntimeException
{
    public function __construct(string $bankCode)
    {
        parent::__construct(sprintf('Bank "%s" cannot be connected with a static token.', $bankCode));
    }
}
