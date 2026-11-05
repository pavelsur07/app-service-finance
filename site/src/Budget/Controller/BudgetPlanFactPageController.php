<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\Service\BudgetPairResolver;
use App\Budget\Application\Service\BudgetPlanFactBuilder;
use App\Budget\Application\View\BudgetPlanFactColumn;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Budget\Repository\BudgetVersionRepository;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вкладка «План–факт» раздела «Бюджет ОПиУ» (ui-ux.md, экран 5). Только чтение.
 * GET /finance/budget/plan-fact?year=&version=&project=&center=&show=&layout=&period=.
 *
 * Вариант по умолчанию — утверждённый на год, без него — первый неархивный; пара по умолчанию —
 * вся компания. Показатель колонок периода: plan / fact / diff (по умолчанию) / percent / all
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
        private readonly BudgetPairResolver $pairs,
        private readonly BudgetPlanFactBuilder $planFactBuilder,
        private readonly ClockInterface $clock,
        private readonly CompanyFacade $companies,
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

        $pairOptions = $this->pairs->options($companyId);
        $project = $request->query->getString('project');
        $center = $request->query->getString('center');
        try {
            $pair = $this->pairs->resolve($companyId, $project, $center);
        } catch (InvalidBudgetPairException) {
            // Пара могла стать неактивной после того, как ссылку сохранили: показываем всю компанию.
            $this->addFlash('warning', 'Выбранная пара «проект × ЦФО» недоступна — показана вся компания.');
            $pair = null;
        }

        $remembered = $request->getSession()->get(self::SESSION_KEY, []);
        $remembered = \is_array($remembered) ? $remembered : [];
        $show = $this->pick($request, $remembered, 'show', self::SHOW) ?? 'diff';
        $period = $this->pick($request, $remembered, 'period', self::PERIOD) ?? 'month';
        $layout = $this->pick($request, $remembered, 'layout', self::LAYOUT);
        $request->getSession()->set(self::SESSION_KEY, array_filter(['show' => $show, 'period' => $period, 'layout' => $layout]));
        $layout ??= 'quarter' === $period ? 'columns' : 'rows';
        $view = null === $version ? null : $this->planFactBuilder->build($version, $pair?->projectDirectionId, $pair?->responsibilityCenterId);
        $periodKind = 'quarter' === $period ? BudgetPlanFactColumn::KIND_QUARTER : BudgetPlanFactColumn::KIND_MONTH;

        // Ссылка на факт ведёт в отчёт ОПиУ, только когда суммы там совпадут. Отчёт берёт проект
        // вместе с дочерними, а при фильтре по ЦФО не показывает строки без ЦФО; план–факт — точный
        // проект, а системному «Общему» добавляет факт без ЦФО.
        $generalCenter = null !== $pair && $this->pairs->isGeneralCenter($companyId, $pair->responsibilityCenterId);
        $reportLinkable = null === $pair || (!$generalCenter && !$this->hasChildProjects($companyId, $pair->projectDirectionId));

        $years = array_values(array_unique([...$this->versions->findYearsByCompanyId($companyId), $currentYear, $currentYear + 1, $year]));
        sort($years);

        return $this->render('budget/plan_fact.html.twig', [
            'year' => $year,
            'years' => $years,
            'versions' => $active,
            'archived' => array_values(array_filter($all, static fn (BudgetVersion $v): bool => BudgetVersionStatus::ARCHIVED === $v->getStatus())),
            'version' => $version,
            'hasApproved' => $hasApproved,
            'pairs' => $pairOptions,
            'pair' => $pair,
            'show' => $show,
            'layout' => $layout,
            'period' => $period,
            'view' => $view,
            'periodColumns' => null === $view ? [] : array_values(array_filter($view->columns, static fn (BudgetPlanFactColumn $c): bool => $c->kind === $periodKind)),
            'reportLinkable' => $reportLinkable,
            'generalCenter' => $generalCenter,
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

    private function hasChildProjects(string $companyId, string $projectDirectionId): bool
    {
        // Список — в порядке дерева: у проекта есть дочерние, если следующий за ним глубже.
        $projects = $this->companies->listProjectDirections($companyId);
        foreach ($projects as $index => $project) {
            if ($project->id === $projectDirectionId) {
                return isset($projects[$index + 1]) && $projects[$index + 1]->level > $project->level;
            }
        }

        return false;
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
