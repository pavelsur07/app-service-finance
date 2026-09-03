<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Enum\NormalizationIssueKind;

final class IssueDescriptionFormatter
{
    /**
     * @param array<string, mixed> $details
     */
    public function humanize(NormalizationIssueKind $kind, array $details): string
    {
        return match ($kind) {
            NormalizationIssueKind::SUM_MISMATCH => 'Сумма операций не сходится с контрольной суммой источника',
            NormalizationIssueKind::MAPPER_FAILURE => 'Не удалось распознать операцию',
            NormalizationIssueKind::UNKNOWN_FIELD => 'В отчёте появилось неизвестное поле',
            NormalizationIssueKind::CURRENCY_MISMATCH => 'В операции смешаны разные валюты',
            NormalizationIssueKind::UNKNOWN_ORDER_STATUS => sprintf(
                'Маркетплейс прислал незнакомый статус заказа: %s',
                is_string($details['rawStatus'] ?? null) ? $details['rawStatus'] : 'значение не сохранено',
            ),
            NormalizationIssueKind::STUCK_ORDER => 'Заказ слишком долго висит в нетерминальном статусе, опрос остановлен',
        };
    }
}
