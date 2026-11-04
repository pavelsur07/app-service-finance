<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\Service\BudgetGridBuilder;
use App\Budget\Application\Service\BudgetPairResolver;
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
 * Сетка варианта: `?project=&center=` — одна активная пара (с комментариями); одно измерение
 * (другое пусто или `all`) — сумма пар этого проекта / ЦФО; без них — сумма всех пар.
 * GET /finance/budget/versions/{id}/grid.
 */
#[Route('/finance/budget/versions/{id}/grid', name: 'budget_version_grid', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class BudgetGridController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetPairResolver $pairs,
        private readonly BudgetGridBuilder $gridBuilder,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $version = $this->versions->findOneByIdAndCompanyId($id, $companyId) ?? throw new BudgetVersionNotFoundException();

        [$project, $center] = $this->pairs->resolveSlice($companyId, $request->query->getString('project'), $request->query->getString('center'));

        return $this->json($this->gridBuilder->build($version, $project, $center));
    }
}
