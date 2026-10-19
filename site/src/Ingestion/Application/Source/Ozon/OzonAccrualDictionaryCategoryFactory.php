<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Entity\OzonAccrualType;
use App\Ingestion\Enum\TransactionType;

/**
 * Статья для типа начисления, которого нет ни в маппингах, ни в каталоге, —
 * из справочника Ozon `accrual/types`, без правки кода.
 *
 * Код строится из `name` (у Ozon он уникален), название — из `description`
 * (русское название, как в кабинете; не уникально, поэтому в код не идёт).
 * Статья остаётся `known = false`: тип по-прежнему стоит в очереди разбора, пока
 * человек не подтвердит его. Группу передаёт вызывающий — из подсказки правил
 * с высокой уверенностью, иначе «Требует классификации».
 *
 * Тип транзакции — тот же запасной, что у `OzonAccrualCategory::unknown()`:
 * смена типа поменяла бы естественный ключ уже загруженных транзакций. По той же
 * причине фабрика никогда не отдаёт статью каталога — даже при совпадении кода.
 */
final readonly class OzonAccrualDictionaryCategoryFactory
{
    public const CODE_PREFIX = 'ozon_';
    public const SORT_ORDER = 9000;

    /** Длины колонок маппинга: `canonical_code` VARCHAR(100), `canonical_label` VARCHAR(255). */
    public const CODE_MAX_LENGTH = 100;
    public const LABEL_MAX_LENGTH = 255;

    /** Запас под суффикс `_<type_id>` при совпадении с кодом каталога. */
    private const TYPE_SUFFIX_RESERVE = 12;

    /**
     * @param string $group группа из подсказки правил (Stage 3) или «Требует классификации»
     */
    public function create(
        OzonAccrualType $type,
        TransactionType $fallbackType,
        string $group = OzonAccrualCategory::UNKNOWN_GROUP,
    ): OzonAccrualCategory {
        $code = self::codeFor($type->getName());

        // Коды каталога семантические, а не выведенные из имени Ozon: совпадение
        // (`SaleCommission` → `ozon_sale_commission`) не доказывает, что это та же
        // услуга. Статью каталога не отдаём — иначе тип стал бы известным и сменил
        // тип транзакции, — а различаем код суффиксом `type_id`.
        if (null !== OzonAccrualCategory::findByCode($code)) {
            $code = sprintf('%s_%d', $code, $type->getTypeId());
        }

        return new OzonAccrualCategory(
            code: $code,
            label: mb_substr($type->getDescription() ?? $type->getName(), 0, self::LABEL_MAX_LENGTH),
            group: '' !== trim($group) ? $group : OzonAccrualCategory::UNKNOWN_GROUP,
            transactionType: $fallbackType,
            sortOrder: self::SORT_ORDER,
            aliases: [$type->getName()],
            known: false,
        );
    }

    /**
     * `AnalyticsPlus` → `ozon_analytics_plus`, `Drop-Off Agent` → `ozon_drop_off_agent`,
     * `B2CContainerPacking` → `ozon_b2c_container_packing`.
     */
    public static function codeFor(string $name): string
    {
        $snake = preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z])([A-Z])/', '/(\d)([A-Z][a-z])/'], '$1_$2', trim($name)) ?? $name;
        $snake = strtolower($snake);
        $snake = trim((string) preg_replace('/[^a-z\d]+/', '_', $snake), '_');

        $code = self::CODE_PREFIX.('' !== $snake ? $snake : substr(hash('sha256', $name), 0, 8));

        // Длинное имя усекается с хвостом из хэша полного имени: код влезает в
        // колонку маппинга вместе с суффиксом `_<type_id>` и остаётся различимым.
        $limit = self::CODE_MAX_LENGTH - self::TYPE_SUFFIX_RESERVE;
        if (\strlen($code) > $limit) {
            $code = rtrim(substr($code, 0, $limit - 9), '_').'_'.substr(hash('sha256', $name), 0, 8);
        }

        return $code;
    }
}
