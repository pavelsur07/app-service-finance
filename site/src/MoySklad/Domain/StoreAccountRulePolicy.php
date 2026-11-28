<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

/**
 * Предложение счёта Баланса складу по названию: первое совпавшее правило, иначе null (решает ИИ).
 * Порядок важен — слова площадок встречаются в браке, транзите и ФФ («Недостачи Ozon», «ФФ К2 Рнд WB»);
 * FBS — товар продавца, а не площадки, поэтому без правила; FBO без названия площадки или обе площадки сразу — тоже
 * (ошибка площадки переписала бы чужой счёт). Счета — коды типового справочника Баланса.
 */
final readonly class StoreAccountRulePolicy
{
    private const MARKETPLACE_WARNING = 'Остатки площадки по API в Баланс не проводятся — проводится только этот склад МойСклад.';

    /** @var list<array{string, ?string, string, bool}> шаблон, код счёта (null — не учитывать), причина, предупреждение */
    private const RULES = [
        ['/(?<!\p{L})(?<!без\s)(?:тест|брак|списан|недостач|корректир|утиль|резерв|виртуальн)/u', null, 'Технический склад: брак, списания, недостачи, резерв или тест — в Баланс не учитывается.', false],
        ['/транзит|в\s+пути/u', 'ACC_INV_TRANSIT', 'Транзитный склад — товар в пути.', false],
        ['/(?<!\p{L})фф(?!\p{L})|фулфил/u', 'ACC_INV_FF', 'Склад фулфилмент-оператора.', false],
        ['/(?<!\p{L})fbs(?!\p{L})/u', null, '', false], // товар продавца: свой склад или ФФ — решает ИИ
        ['/офис|основной\s+склад|собственный\s+склад/u', 'ACC_INV_WAREHOUSE', 'Собственный склад компании.', false],
        ['/^(?=.*(?<!\p{L})(?:ozon|озон)(?!\p{L}))(?=.*(?<!\p{L})(?:wb|wildberries|вайлдберриз)(?!\p{L}))/u', null, '', false], // обе площадки — решает ИИ
        ['/(?<!\p{L})(?:ozon|озон)(?!\p{L})/u', 'ACC_INV_OZON', 'Склад Ozon.', true],
        ['/(?<!\p{L})(?:wb|wildberries|вайлдберриз)(?!\p{L})/u', 'ACC_INV_WB', 'Склад Wildberries.', true],
    ];

    public function suggest(string $storeName): ?StoreAccountSuggestion
    {
        $name = str_replace('ё', 'е', mb_strtolower(trim($storeName)));
        if ('' === $name) {
            return null;
        }
        foreach (self::RULES as [$pattern, $accountCode, $reason, $warn]) {
            if (1 !== preg_match($pattern, $name)) {
                continue;
            }
            if ('' === $reason) {
                return null;
            }

            return new StoreAccountSuggestion($accountCode, null === $accountCode, $reason, $warn ? self::MARKETPLACE_WARNING : null);
        }

        return null;
    }
}
