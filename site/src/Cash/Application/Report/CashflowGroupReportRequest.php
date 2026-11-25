<?php

declare(strict_types=1);

namespace App\Cash\Application\Report;

use App\Cash\Application\DTO\CashflowGroupRangeTooLarge;
use App\Cash\Application\DTO\CashflowGroupReport;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Общая часть страницы и выгрузки консолидированного отчёта: период из запроса (по умолчанию —
 * текущий месяц) и доступ. MVP: отчёт видит только владелец всех компаний группы — группу задаёт
 * он же, отдельных ролей на группу нет.
 */
final readonly class CashflowGroupReportRequest
{
    public function __construct(
        private BuildCashflowGroupReport $build,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return ($reconciliation is true ? CashflowGroupReport|null : CashflowGroupReport|CashflowGroupRangeTooLarge|null) null — активная компания вне группы
     *
     * @throws AccessDeniedException пользователь не владелец какой-то компании группы
     */
    public function __invoke(Request $request, Company $activeCompany, User $user, bool $reconciliation = false): CashflowGroupReport|CashflowGroupRangeTooLarge|null
    {
        $today = $this->clock->now();
        $from = self::date($request->query->getString('from')) ?? $today->modify('first day of this month');
        $to = self::date($request->query->getString('to')) ?? $today->modify('last day of this month');
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $group = $reconciliation || 'companies' === $request->query->getString('view') ? null : self::group($request);

        return ($this->build)(
            (string) $activeCompany->getId(), $from->setTime(0, 0), $to->setTime(0, 0),
            $group, (string) $user->getId(),
        );
    }

    public static function group(Request $request): string
    {
        $group = $request->query->getString('group');

        return \in_array($group, ['week', 'quarter', 'year'], true) ? $group : 'month';
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
