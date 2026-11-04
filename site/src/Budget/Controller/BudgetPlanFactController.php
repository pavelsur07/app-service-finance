<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\Service\BudgetPairResolver;
use App\Budget\Application\Service\BudgetPlanFactBuilder;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Repository\BudgetVersionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * План–факт варианта: `?project=&center=` — одна активная пара (план и факт этой пары), без них —
 * вся компания. GET /finance/budget/versions/{id}/plan-fact.
 */
#[Route('/finance/budget/versions/{id}/plan-fact', name: 'budget_version_plan_fact', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class BudgetPlanFactController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetPairResolver $pairs,
        private readonly BudgetPlanFactBuilder $planFactBuilder,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $version = $this->versions->findOneByIdAndCompanyId($id, $companyId) ?? throw new BudgetVersionNotFoundException();

        $pair = $this->pairs->resolve($companyId, $request->query->getString('project'), $request->query->getString('center'));

        return $this->json($this->planFactBuilder->build($version, $pair?->projectDirectionId, $pair?->responsibilityCenterId));
    }
}
