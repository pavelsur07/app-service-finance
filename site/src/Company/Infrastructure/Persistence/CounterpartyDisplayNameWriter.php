<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Запись авто-названия условным UPDATE: пересчёт читает порцию, а пользователь в это время может
 * переименовать контрагента или задать ручное название. Строка обновляется, только если сырое
 * name всё ещё то, от которого посчитано, и источник не manual — иначе правка пользователя
 * была бы молча перезаписана, а название от старого name закрепилось бы навсегда.
 */
final class CounterpartyDisplayNameWriter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** true — записано; false — строку изменили параллельно, пересчёт возьмёт её в следующий раз. */
    public function writeAutoIfUnchanged(string $id, string $rawNameSeen, ?string $displayName, int $rulesVersion): bool
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE "counterparty"
                SET display_name = :displayName, display_name_source = :auto, display_rules_version = :version
                WHERE id = :id
                  AND name = :rawNameSeen
                  AND (display_name_source IS NULL OR display_name_source = :auto)
                SQL,
            ['displayName' => $displayName, 'auto' => 'auto', 'version' => $rulesVersion, 'id' => $id, 'rawNameSeen' => $rawNameSeen],
            ['version' => ParameterType::INTEGER],
        );

        return 1 === $affected;
    }

    /**
     * «Применить» в списке: предложение правил как ручное название (источник manual — пересчёт его
     * не перезаписывает). Только если контрагент той же компании, не в архиве, name то же, от
     * которого посчитано предложение, и название не задано вручную — иначе false.
     */
    public function writeManualIfUnchanged(string $id, string $companyId, string $rawNameSeen, string $displayName): bool
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE "counterparty"
                SET display_name = :displayName, display_name_source = :manual, display_rules_version = NULL, updated_at = :now
                WHERE id = :id
                  AND company_id = :companyId
                  AND name = :rawNameSeen
                  AND is_archived = false
                  AND (display_name_source IS NULL OR display_name_source = :auto)
                SQL,
            [
                'displayName' => $displayName,
                'manual' => 'manual',
                'auto' => 'auto',
                'now' => new \DateTimeImmutable(),
                'id' => $id,
                'companyId' => $companyId,
                'rawNameSeen' => $rawNameSeen,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return 1 === $affected;
    }

    /**
     * Текущее отображаемое название и источник прямо из БД — после false от условной записи
     * (повторный клик, параллельная вкладка), когда снимку сущности верить нельзя.
     *
     * @return array{display_name: ?string, display_name_source: ?string}|null
     */
    public function currentState(string $id, string $companyId): ?array
    {
        /** @var array{display_name: ?string, display_name_source: ?string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT display_name, display_name_source FROM "counterparty" WHERE id = :id AND company_id = :companyId',
            ['id' => $id, 'companyId' => $companyId],
        );

        return false === $row ? null : $row;
    }

    /** «Вернуть исходное»: снять ручное название (не в архиве). false — ручного названия уже нет. */
    public function resetManual(string $id, string $companyId): bool
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE "counterparty"
                SET display_name = NULL, display_name_source = NULL, display_rules_version = NULL, updated_at = :now
                WHERE id = :id
                  AND company_id = :companyId
                  AND is_archived = false
                  AND display_name_source = :manual
                SQL,
            ['now' => new \DateTimeImmutable(), 'id' => $id, 'companyId' => $companyId, 'manual' => 'manual'],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return 1 === $affected;
    }

    /**
     * Сырое name сменилось — авто-название в БД посчитано от старого. Отдельным UPDATE, а не
     * полями сущности: если пересчёт записал его после загрузки карточки, в снимке Doctrine поля
     * пустые, и «присвоить null» flush бы пропустил. Ручное название не трогается.
     */
    public function resetAuto(string $id): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE "counterparty"
                SET display_name = NULL, display_name_source = NULL, display_rules_version = NULL
                WHERE id = :id
                  AND (display_name_source IS NULL OR display_name_source = :auto)
                SQL,
            ['id' => $id, 'auto' => 'auto'],
        );
    }
}
