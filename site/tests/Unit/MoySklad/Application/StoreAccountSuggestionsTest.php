<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Application;

use App\Balance\Application\DTO\BalancePostingAccount;
use App\MoySklad\Application\StoreAccountSuggestions;
use App\MoySklad\Domain\StoreAccountRulePolicy;
use PHPUnit\Framework\TestCase;

final class StoreAccountSuggestionsTest extends TestCase
{
    public function testResolvesCodeToActiveAssetAccountOfCompany(): void
    {
        $accounts = [
            new BalancePostingAccount('a-ff', 'ACC_INV_FF', 'Склады фулфилмента', 'Активы / Склады ФФ', true),
            new BalancePostingAccount('a-transit', 'ACC_INV_TRANSIT', 'Товар в пути', 'Пассив?', false),
        ];
        $stores = [
            ['externalId' => 's-ff', 'name' => 'ФФ К2', 'accountId' => null, 'excluded' => false],
            ['externalId' => 's-brak', 'name' => 'Брак', 'accountId' => null, 'excluded' => false],
            ['externalId' => 's-ozon', 'name' => 'Ozon', 'accountId' => null, 'excluded' => false],
            ['externalId' => 's-transit', 'name' => 'Транзит Wb', 'accountId' => null, 'excluded' => false],
            ['externalId' => 's-linked', 'name' => 'ФФ Марлин', 'accountId' => 'a-ff', 'excluded' => false],
            ['externalId' => 's-excluded', 'name' => 'ФФ Тучка', 'accountId' => null, 'excluded' => true],
            ['externalId' => 's-unknown', 'name' => 'Газелька', 'accountId' => null, 'excluded' => false],
        ];

        $result = (new StoreAccountSuggestions(new StoreAccountRulePolicy()))->forStores($stores, $accounts);

        self::assertSame(['s-ff', 's-brak'], array_keys($result), 'Нет счёта ACC_INV_OZON, счёт не актива, назначенные и неизвестные склады — без предложения.');
        self::assertSame('a-ff', $result['s-ff']->target);
        self::assertSame('Склады фулфилмента', $result['s-ff']->label);
        self::assertSame('excluded', $result['s-brak']->target);
        self::assertSame('Не учитывать', $result['s-brak']->label);
    }
}
