<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Company\Facade\CompanyFacade;
use App\Company\Facade\ConsolidationGroupFacadeInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class IntragroupGroupAccess
{
    public function __construct(private ConsolidationGroupFacadeInterface $groups, private CompanyFacade $companies)
    {
    }

    /** @return non-empty-list<string> головная первой; вызывается в транзакции до остальных блокировок */
    public function lockedMembers(string $activeCompanyId, string $userId, ?string $expectedHead = null): array
    {
        $head = $this->groups->lockedHeadOf($activeCompanyId);
        if (null !== $expectedHead && $expectedHead !== $head) {
            throw new AccessDeniedException('Группа консолидации изменилась. Обновите сверку.');
        }
        $members = null === $head ? [] : $this->groups->members($head);
        if ([] === $members || $head !== $members[0] || !\in_array($activeCompanyId, $members, true)) {
            throw new AccessDeniedException('Компания вне группы консолидации.');
        }
        $lockOrder = $members;
        sort($lockOrder);
        foreach ($lockOrder as $companyId) {
            if (!$this->companies->financialAccess($companyId, $userId, true)['owner']) {
                throw new AccessDeniedException('Сверка доступна владельцу всех компаний группы.');
            }
        }

        return $members;
    }

    /** @param list<string> $members */
    public function assertOwner(array $members, string $userId): void
    {
        if ([] !== array_diff($members, $this->companies->ownedCompanyIds($userId))) {
            throw new AccessDeniedException('Консолидированный отчёт доступен владельцу всех компаний группы.');
        }
    }
}
