<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Domain;

use App\MoySklad\Domain\StoreAccountRulePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Правила предложения счёта складу; названия — склады эталона (docs/tasks/moysklad-store-account-suggest/stages/stage-0.md). */
final class StoreAccountRulePolicyTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool}> */
    public static function stores(): iterable
    {
        yield 'брак' => ['Брак', null, true];
        yield 'брак со словом площадки' => ['Брак и списания Ozon/Wb', null, true];
        yield 'брак со словом ФФ' => ['Брак и списания ФФ', null, true];
        yield 'недостачи с площадкой' => ['Недостачи Ozon', null, true];
        yield 'корректировка' => ['Корректировка (февраль-июль)', null, true];
        yield 'тест сильнее ФФ' => ['ФФ Тучка (Тест)', null, true];
        yield 'резерв' => ['Резерв под заказ', null, true];
        yield 'транзит площадки' => ['Транзит Ozon', 'ACC_INV_TRANSIT', false];
        yield 'транзитный склад' => ['Транзитный склад до ФФ', 'ACC_INV_TRANSIT', false];
        yield 'в пути' => ['Товар в пути', 'ACC_INV_TRANSIT', false];
        yield 'ФФ' => ['ФФ BoxBuddy', 'ACC_INV_FF', false];
        yield 'ФФ сильнее площадки и FBS' => ['ФФ Ивлев Мск Wb FBS', 'ACC_INV_FF', false];
        yield 'фулфилмент' => ['Фулфилмент Казань', 'ACC_INV_FF', false];
        yield 'офис' => ['Офис КЛЭЙ ФАН', 'ACC_INV_WAREHOUSE', false];
        yield 'основной склад' => ['Основной склад (офис)', 'ACC_INV_WAREHOUSE', false];
        yield 'ozon' => ['Ozon', 'ACC_INV_OZON', false];
        yield 'озон кириллицей' => ['Склад ОЗОН', 'ACC_INV_OZON', false];
        yield 'fbo со словом WB' => ['WB FBO Коледино', 'ACC_INV_WB', false];
        yield 'fbo с Wildberries' => ['FBO Wildberries', 'ACC_INV_WB', false];
        yield 'fbo с Ozon' => ['Ozon FBO Хоругвино', 'ACC_INV_OZON', false];
        yield 'wb' => ['WB Коледино', 'ACC_INV_WB', false];
        yield 'wildberries' => ['Wildberries', 'ACC_INV_WB', false];
    }

    #[DataProvider('stores')]
    public function testSuggests(string $name, ?string $accountCode, bool $excluded): void
    {
        $suggestion = (new StoreAccountRulePolicy())->suggest($name);

        self::assertNotNull($suggestion);
        self::assertSame($accountCode, $suggestion->accountCode);
        self::assertSame($excluded, $suggestion->excluded);
        self::assertNotSame('', $suggestion->reason);
    }

    /** @return iterable<string, array{string}> */
    public static function unknown(): iterable
    {
        yield 'FBS с площадкой — товар продавца' => ['FBS OZON'];
        yield 'FBS у оператора' => ['FBS К2 (РНД)'];
        yield 'нет признаков' => ['Газелька'];
        yield 'город без признаков' => ['Элфулл (Самара)'];
        yield 'ФФ внутри слова не считается' => ['ФФормат'];
        yield 'wb внутри слова не считается' => ['Swbox'];
        yield 'пусто' => ['  '];
        yield 'fbo без площадки' => ['FBO'];
        yield 'обе площадки' => ['Ozon/WB'];
        yield 'отрицание брака' => ['Годный товар (без брака)'];
        yield 'тест внутри слова' => ['Протестированные'];
    }

    #[DataProvider('unknown')]
    public function testLeavesUnknownToAi(string $name): void
    {
        self::assertNull((new StoreAccountRulePolicy())->suggest($name));
    }

    public function testWarnsOnlyForMarketplaceStores(): void
    {
        $policy = new StoreAccountRulePolicy();

        self::assertNotNull($policy->suggest('Ozon')?->warning);
        self::assertNotNull($policy->suggest('Wildberries')?->warning);
        self::assertNull($policy->suggest('ФФ К2 Рнд WB')?->warning);
        self::assertNull($policy->suggest('Транзит Wb')?->warning);
    }
}
