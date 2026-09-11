<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * Отметка «период нужно пересобрать» на конкретном этапе закрытия.
 *
 * Ставится, когда замена строк перезагруженного дня сняла привязку к
 * предварительному ОПиУ: документ этого этапа с этого момента расходится с
 * источником, и ночной пересбор обязан его переделать.
 *
 * Почему отметка, а не поиск по состоянию. Сканировать «все предварительно
 * закрытые периоды» нельзя: успешный пересбор снова выставляет флаг
 * предварительности, поэтому период оставался бы в выборке вечно, и нетронутые
 * исторические документы удалялись бы и пересоздавались каждую ночь без единой
 * причины. Отметка же снимается ровно тогда, когда работа сделана.
 *
 * Флаг живёт в `settings` — колонка `json`, поэтому значение приводится к
 * `jsonb` на время правки и обратно: у `json` нет ни `jsonb_set`, ни `#-`.
 */
final class PreliminaryRebuildFlagQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function mark(string $companyId, MarketplaceType $marketplace, int $year, int $month, CloseStage $stage): int
    {
        return (int) $this->connection->executeStatement(
            sprintf(
                // Слияние, а не jsonb_set: последний не создаёт промежуточный
                // объект, и при отсутствующем `needs_preliminary_rebuild`
                // возвращал бы настройки без изменений — молча, не падая.
                // Проверено на настоящем PostgreSQL.
                //
                // Имя этапа подставляется литералом, а не параметром: у
                // операторов json нетипизированный параметр PostgreSQL выводит
                // не в text. Значение приходит из enum, подстановка безопасна.
                "UPDATE marketplace_month_closes
                 SET settings = (
                         COALESCE(settings::jsonb, '{}'::jsonb)
                         || jsonb_build_object(
                                'needs_preliminary_rebuild',
                                COALESCE(settings::jsonb -> 'needs_preliminary_rebuild', '{}'::jsonb)
                                || jsonb_build_object('%s', true)
                            )
                     )::json,
                     updated_at = NOW()
                 WHERE company_id = :companyId
                   AND marketplace = :marketplace
                   AND year = :year
                   AND month = :month",
                $stage->value,
            ),
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace->value,
                'year' => $year,
                'month' => $month,
            ],
        );
    }

    public function clear(string $companyId, MarketplaceType $marketplace, int $year, int $month, CloseStage $stage): int
    {
        return (int) $this->connection->executeStatement(
            sprintf(
                "UPDATE marketplace_month_closes
                 SET settings = (COALESCE(settings::jsonb, '{}'::jsonb) #- '{needs_preliminary_rebuild,%s}')::json,
                     updated_at = NOW()
                 WHERE company_id = :companyId
                   AND marketplace = :marketplace
                   AND year = :year
                   AND month = :month",
                $stage->value,
            ),
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace->value,
                'year' => $year,
                'month' => $month,
            ],
        );
    }
}
