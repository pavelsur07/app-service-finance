<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\Service\BudgetPlanFactBuilder;
use App\Budget\Application\Service\BudgetPlanFactFilterResolver;
use App\Budget\Application\View\BudgetPlanFactColumn;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Repository\BudgetVersionRepository;
use App\Shared\Service\ActiveCompanyService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вкладка «План–факт» раздела «Бюджет ОПиУ» (ui-ux.md, экран 5). Только чтение.
 * GET /finance/budget/plan-fact?year=&version=&projects[]=&centers[]=&show=&layout=&period=.
 *
 * Вариант по умолчанию — утверждённый на год, без него — первый неархивный. Проекты (с дочерними)
 * и ЦФО — мультивыбор, по умолчанию вся компания; старый `?project=&center=` читается как списки
 * из одного значения (BudgetPlanFactFilterResolver). Показатель колонок периода: plan / fact / diff (по умолчанию) / percent / all
 * («Все показатели»); раскладка «Все показатели»: columns / rows (по умолчанию: месяцы — rows,
 * кварталы — columns); период: month (по умолчанию) / quarter. Показатель, раскладка и период
 * запоминаются в сессии (BUDGET-SPEC §6: выбор запоминается).
 */
#[Route('/finance/budget/plan-fact', name: 'budget_plan_fact', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class BudgetPlanFactPageController extends AbstractController
{
    private const array SHOW = ['plan', 'fact', 'diff', 'percent', 'all'];
    private const array LAYOUT = ['columns', 'rows'];
    private const array PERIOD = ['month', 'quarter'];
    private const string SESSION_KEY = 'budget_plan_fact_view';

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetPlanFactFilterResolver $filters,
        private readonly BudgetPlanFactBuilder $planFactBuilder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $currentYear = (int) $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y');
        $year = $request->query->getInt('year', $currentYear);
        if ($year < BudgetVersion::MIN_YEAR || $year > BudgetVersion::MAX_YEAR) {
            $year = $currentYear;
        }

        $versionId = $request->query->getString('version');
        if ('' !== $versionId) {
            $version = $this->versions->findOneByIdAndCompanyId($versionId, $companyId) ?? throw new BudgetVersionNotFoundException();
            $year = $version->getYear();
        }
        $all = $this->versions->findByCompanyIdAndYear($companyId, $year);
        $active = array_values(array_filter($all, static fn (BudgetVersion $v): bool => BudgetVersionStatus::ARCHIVED !== $v->getStatus()));
        // Утверждённый — первым в списке репозитория.
        $version ??= $active[0] ?? null;
        $hasApproved = [] !== $active && BudgetVersionStatus::APPROVED === $active[0]->getStatus();

        $filter = $this->filters->resolve($companyId, $this->ids($request, 'projects', 'project'), $this->ids($request, 'centers', 'center'));

        $remembered = $request->getSession()->get(self::SESSION_KEY, []);
        $remembered = \is_array($remembered) ? $remembered : [];
        $show = $this->pick($request, $remembered, 'show', self::SHOW) ?? 'diff';
        $period = $this->pick($request, $remembered, 'period', self::PERIOD) ?? 'month';
        $layout = $this->pick($request, $remembered, 'layout', self::LAYOUT);
        $request->getSession()->set(self::SESSION_KEY, array_filter(['show' => $show, 'period' => $period, 'layout' => $layout]));
        $layout ??= 'quarter' === $period ? 'columns' : 'rows';
        $view = null === $version ? null : $this->planFactBuilder->buildFiltered($version, $filter);
        $periodKind = 'quarter' === $period ? BudgetPlanFactColumn::KIND_QUARTER : BudgetPlanFactColumn::KIND_MONTH;

        $years = array_values(array_unique([...$this->versions->findYearsByCompanyId($companyId), $currentYear, $currentYear + 1, $year]));
        sort($years);

        return $this->render('budget/plan_fact.html.twig', [
            'year' => $year,
            'years' => $years,
            'versions' => $active,
            'archived' => array_values(array_filter($all, static fn (BudgetVersion $v): bool => BudgetVersionStatus::ARCHIVED === $v->getStatus())),
            'version' => $version,
            'hasApproved' => $hasApproved,
            'filter' => $filter,
            'show' => $show,
            'layout' => $layout,
            'period' => $period,
            'view' => $view,
            'periodColumns' => null === $view ? [] : array_values(array_filter($view->columns, static fn (BudgetPlanFactColumn $c): bool => $c->kind === $periodKind)),
            'ytd' => null === $view ? null : $this->column($view->columns, BudgetPlanFactColumn::YTD),
        ]);
    }

    /**
     * Значение из адреса, иначе запомненное в сессии; неизвестное — null.
     *
     * @param array<mixed> $remembered
     * @param list<string> $allowed
     */
    private function pick(Request $request, array $remembered, string $key, array $allowed): ?string
    {
        $value = $request->query->has($key) ? $request->query->getString($key) : ($remembered[$key] ?? null);

        return \is_string($value) && \in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Список id из адреса (`?projects[]=`), иначе старый одиночный параметр (`?project=`).
     *
     * @return list<string>
     */
    private function ids(Request $request, string $listKey, string $legacyKey): array
    {
        $raw = $request->query->has($listKey) ? $request->query->all($listKey) : [$request->query->get($legacyKey)];

        return array_values(array_filter($raw, static fn (mixed $id): bool => \is_string($id) && '' !== $id));
    }

    /**
     * @param list<BudgetPlanFactColumn> $columns
     */
    private function column(array $columns, string $key): ?BudgetPlanFactColumn
    {
        foreach ($columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }
}
