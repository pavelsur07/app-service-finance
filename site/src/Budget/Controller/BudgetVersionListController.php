<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\Service\BudgetYearTotals;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Repository\BudgetVersionRepository;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Security\ModuleAccess;
use App\Finance\Facade\FinanceFacade;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список вариантов бюджета ОПиУ за год. GET /finance/budget?year=2027.
 */
#[Route('/finance/budget', name: 'budget_index', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class BudgetVersionListController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BudgetVersionRepository $versions,
        private readonly ClockInterface $clock,
        private readonly CompanyFacade $companies,
        private readonly BudgetYearTotals $yearTotals,
        private readonly FinanceFacade $finance,
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

        $all = $this->versions->findByCompanyIdAndYear($companyId, $year);
        $active = array_values(array_filter($all, static fn (BudgetVersion $v): bool => BudgetVersionStatus::ARCHIVED !== $v->getStatus()));
        $archived = array_values(array_filter($all, static fn (BudgetVersion $v): bool => BudgetVersionStatus::ARCHIVED === $v->getStatus()));
        $years = array_values(array_unique([...$this->versions->findYearsByCompanyId($companyId), $currentYear, $currentYear + 1, $year]));
        sort($years);
        $names = [];
        foreach ($all as $version) {
            $names[$version->getId()] = $version->getName();
        }
        $user = $this->getUser();
        $yearTotals = [] === $all ? null : $this->yearTotals->netProfit($companyId, $year, array_keys($names));
        $canWrite = $this->isGranted(ModuleAccess::FINANCE_WRITE);
        // «Из факта {год−1}»: доступно, только если факт прошлого года есть.
        $hasLastYearFact = $canWrite && $year - 1 >= BudgetVersion::MIN_YEAR
            && $this->finance->hasPlFactInYear($companyId, $year - 1);
        // Ошибка окна «Новый вариант» (CreateBudgetVersionController): открыть окно снова с введённым.
        $newForm = $request->hasSession() ? $request->getSession()->remove(CreateBudgetVersionController::FORM_STATE) : null;

        return $this->render('budget/index.html.twig', [
            'year' => $year,
            'years' => $years,
            'versions' => $active,
            'archived' => $archived,
            'names' => $names,
            'yearTotals' => $yearTotals,
            'canWrite' => $canWrite,
            'hasLastYearFact' => $hasLastYearFact,
            'newForm' => \is_array($newForm) ? $newForm : null,
            'isOwner' => $user instanceof User && $this->companies->isOwner($companyId, (string) $user->getId()),
            'newVersionId' => Uuid::uuid7()->toString(),
        ]);
    }
}
