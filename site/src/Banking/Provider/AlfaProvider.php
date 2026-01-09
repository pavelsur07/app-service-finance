<?php

declare(strict_types=1);

namespace App\Banking\Provider;

use App\Banking\Contract\BankProviderInterface;
use App\Banking\Dto\AccountDto;
use App\Banking\Dto\Cursor;
use App\Banking\Dto\TransactionDto;

final class AlfaProvider implements BankProviderInterface
{
    public function getCode(): string
    {
        return 'alfa';
    }

    /**
     * MVP-заглушка: возвращает счета из переданных auth-секретов, если там есть структура:
     * [
     *   'accounts' => [
     *      ['externalId' => 'acc_123', 'number' => '40817...', 'currency' => 'RUB', 'displayName' => '...'],
     *      ...
     *   ]
     * ]
     * Если ничего не передано — вернёт пустой список (это безопасно для запуска).
     */
    public function fetchAccounts(array $authSecrets): array
    {
        $out = [];

        if (isset($authSecrets['accounts']) && is_array($authSecrets['accounts'])) {
            foreach ($authSecrets['accounts'] as $a) {
                $externalId = isset($a['externalId']) ? (string) $a['externalId'] : null;
                $number = isset($a['number']) ? (string) $a['number'] : '';
                $currency = isset($a['currency']) ? (string) $a['currency'] : 'RUB';
                $displayName = isset($a['displayName']) ? (string) $a['displayName'] : null;

                if (null === $externalId || '' === $externalId) {
                    // пропускаем записи без внешнего ID (по контракту он обязателен)
                    continue;
                }

                $out[] = new AccountDto(
                    externalId: $externalId,
                    number: $number,
                    currency: strtoupper($currency),
                    displayName: $displayName
                );
            }
        }

        return $out;
    }

    /**
     * MVP-заглушка: возвращает пустой набор транзакций и nextCursor = null.
     * Готово к замене на реальные REST-вызовы банка с маппингом в TransactionDto.
     *
     * Контракт:
     * - $accountExternalId — ИД счёта в банке (из AccountDto.externalId)
     * - $cursor — курсор инкрементальной загрузки (sinceId/sinceDate), может быть null
     * - $since/$until — границы периода
     *
     * Верните из реальной интеграции массив:
     * ['transactions' => TransactionDto[], 'nextCursor' => ?Cursor]
     */
    public function fetchTransactions(
        array $authSecrets,
        string $accountExternalId,
        ?Cursor $cursor,
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
    ): array {
        // ⚠️ Здесь позже добавите реальные HTTP-вызовы и маппинг ответа в TransactionDto.
        // Ниже оставляем заготовку для nextCursor (null = данных больше нет).
        return [
            'transactions' => [],
            'nextCursor' => null,
        ];
    }

    /**
     * Вспомогалка (если понадобится при реальном маппинге): детерминированный fallback externalId,
     * когда у провайдера нет стабильного ИД транзакции.
     */
    private function makeDeterministicExternalId(TransactionDto $tx): string
    {
        $parts = [
            $tx->accountExternalId,
            (string) $tx->amountMinor,
            $tx->currency,
            $tx->direction,
            $tx->postedAt->format(\DateTimeInterface::ATOM),
            $tx->description,
            $tx->counterparty ?? '',
        ];

        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }
}
