<?php

declare(strict_types=1);

namespace App\BankSync\Domain;

use App\BankSync\Enum\BankCode;

/**
 * Всё, что нужно адаптеру для запроса к банку от имени подключения. Токен расшифровывается
 * перед загрузкой и живёт только в памяти процесса; в логи и исключения объект не попадает.
 */
final readonly class BankAccess
{
    public function __construct(
        public string $connectionId,
        public BankCode $bankCode,
        public string $baseUrl,
        #[\SensitiveParameter]
        public string $token,
    ) {
    }

    /**
     * @return array{connectionId: string, bankCode: string, baseUrl: string}
     */
    public function __debugInfo(): array
    {
        return [
            'connectionId' => $this->connectionId,
            'bankCode' => $this->bankCode->value,
            'baseUrl' => $this->baseUrl,
        ];
    }
}
