<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

/**
 * Предложение статьи для нового типа начисления Ozon: что поставит справочник
 * и откуда взялась группа.
 *
 * `groupSource`: `ozon_report` — подтверждено отчётом кабинета; `rule` — правило
 * высокой уверенности; `rule_medium` — только подсказка; `none` — группы нет.
 */
final readonly class OzonAccrualTypeProposal
{
    public const GROUP_SOURCE_OZON_REPORT = 'ozon_report';
    public const GROUP_SOURCE_RULE = 'rule';
    public const GROUP_SOURCE_RULE_MEDIUM = 'rule_medium';
    public const GROUP_SOURCE_NONE = 'none';

    public function __construct(
        public string $code,
        public string $label,
        public ?string $groupCode,
        public ?string $groupLabel,
        public string $groupSource,
    ) {
    }
}
