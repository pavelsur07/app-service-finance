<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
}
