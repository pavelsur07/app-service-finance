<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

/**
 * Обезличивает ответ Альфы для фикстур тестов. Числа, логические значения и строки под
 * ключами из `KEPT_KEYS` (даты, валюта, направление, статус, ключи операций, ссылки
 * страниц) сохраняются. Любая другая строка — номер, ИНН, наименование, назначение
 * платежа, ФИО — заменяется детерминированно: одинаковое значение в разных ответах даёт
 * одинаковую замену, цифры остаются цифрами той же длины (номер счёта остаётся номером).
 */
final readonly class AlfaFixtureAnonymizer
{
    private const KEPT_KEYS = [
        'uuid', 'transactionId', 'direction', 'status', 'currency', 'currencyName', 'currencyCode',
        'operationDate', 'documentDate', 'valueDate', 'statementDate', 'composedDateTime', 'lastMovementDate',
        'schemeName', 'rel', 'totalPages', 'page', 'type', 'operationCode', 'debitCreditIndicator',
    ];

    /**
     * @param array<mixed> $response
     * @param string $salt одна соль на прогон: замены согласованы между файлами
     *                     прогона, но не восстанавливаются по словарю
     *
     * @return array<mixed>
     */
    public function anonymize(array $response, string $salt): array
    {
        $result = [];
        foreach ($response as $key => $value) {
            $result[$key] = match (true) {
                \is_array($value) => $this->anonymize($value, $salt),
                \is_string($value) && !(\is_string($key) && \in_array($key, self::KEPT_KEYS, true)) => $this->replace($value, $salt),
                default => $value,
            };
        }

        return $result;
    }

    private function replace(string $value, string $salt): string
    {
        if ('' === $value) {
            return $value;
        }
        $hash = hash('sha256', $salt.$value);
        if (1 === preg_match('/^\d+$/', $value)) {
            $digits = '';
            foreach (str_split(str_repeat($hash, (int) ceil(\strlen($value) / 64) + 1)) as $char) {
                $digits .= (string) (hexdec($char) % 10);
            }

            return substr($digits, 0, \strlen($value));
        }

        return 'anon-'.substr($hash, 0, 12);
    }
}
