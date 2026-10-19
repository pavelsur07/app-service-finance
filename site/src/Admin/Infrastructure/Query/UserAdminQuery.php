<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Query;

use App\Admin\Application\DTO\CabinetChoiceView;
use App\Admin\Application\DTO\UserCabinetView;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read-only выборки страницы /admin/users.
 *
 * Владелец кабинета определяется только по companies.user_id (источник истины).
 * Членство учитывается только в статусе ACTIVE; зеркальные OWNER-членства
 * владельцев (CompanyOwnerMembershipCreator) не дублируются — собственный кабинет
 * уже учтён как «Владелец».
 */
final readonly class UserAdminQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Кабинеты пользователей одной страницы списка (batch, без N+1).
     *
     * @param list<string> $userIds
     *
     * @return array<string, list<UserCabinetView>> ключ — user id
     */
    public function cabinetsByUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        if ([] === $userIds) {
            return [];
        }

        $types = ['userIds' => ArrayParameterType::STRING];

        $ownedRows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name, c.inn, c.user_id
             FROM companies c
             WHERE c.user_id IN (:userIds)',
            ['userIds' => $userIds],
            $types,
        );

        $memberRows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name, c.inn, m.user_id
             FROM company_members m
             INNER JOIN companies c ON c.id = m.company_id
             WHERE m.user_id IN (:userIds) AND m.status = \'ACTIVE\'',
            ['userIds' => $userIds],
            $types,
        );

        $byUser = [];

        foreach ($ownedRows as $row) {
            $byUser[(string) $row['user_id']][(string) $row['id']] = new UserCabinetView(
                id: (string) $row['id'],
                name: (string) $row['name'],
                inn: null !== $row['inn'] ? (string) $row['inn'] : null,
                owner: true,
            );
        }

        foreach ($memberRows as $row) {
            $userId = (string) $row['user_id'];
            $companyId = (string) $row['id'];
            if (isset($byUser[$userId][$companyId])) {
                continue;
            }
            $byUser[$userId][$companyId] = new UserCabinetView(
                id: $companyId,
                name: (string) $row['name'],
                inn: null !== $row['inn'] ? (string) $row['inn'] : null,
                owner: false,
            );
        }

        foreach ($byUser as $userId => $cabinets) {
            $cabinets = array_values($cabinets);
            usort($cabinets, static fn (UserCabinetView $a, UserCabinetView $b): int => $a->name <=> $b->name);
            $byUser[$userId] = $cabinets;
        }

        return $byUser;
    }

    /**
     * Справочник кабинетов для фильтра страницы.
     *
     * @return list<CabinetChoiceView>
     */
    public function cabinetChoices(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name, c.inn FROM companies c ORDER BY c.name ASC',
        );

        return array_map(
            static fn (array $row): CabinetChoiceView => new CabinetChoiceView(
                id: (string) $row['id'],
                name: (string) $row['name'],
                inn: null !== $row['inn'] ? (string) $row['inn'] : null,
            ),
            $rows,
        );
    }
}
