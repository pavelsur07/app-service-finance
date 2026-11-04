<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\Service\BudgetPairResolver;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Repository\BudgetVersionRepository;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Страница варианта: шапка, статус, действия и точка монтирования сетки (React-остров).
 * GET /finance/budget/versions/{id}.
 */
#[Route('/finance/budget/versions/{id}', name: 'budget_version_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class BudgetVersionShowController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BudgetVersionRepository $versions,
        private readonly CompanyFacade $companies,
        private readonly BudgetPairResolver $pairs,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $version = $this->versions->findOneByIdAndCompanyId($id, $companyId) ?? throw new BudgetVersionNotFoundException();
        $user = $this->getUser();
        $isOwner = $user instanceof User && $this->companies->isOwner($companyId, (string) $user->getId());

        return $this->render('budget/show.html.twig', [
            'version' => $version,
            'isOwner' => $isOwner,
            'props' => [
                'versionId' => $version->getId(),
                'year' => $version->getYear(),
                'editable' => $version->isEditable(),
                'canWrite' => $this->isGranted(ModuleAccess::FINANCE_WRITE),
                'isOwner' => $isOwner,
                'pairs' => $this->pairs->options($companyId),
                'gridUrl' => $this->generateUrl('budget_version_grid', ['id' => $version->getId()]),
                'cellsUrl' => $this->generateUrl('budget_version_cells', ['id' => $version->getId()]),
                'fillUrl' => $this->generateUrl('budget_version_fill', ['id' => $version->getId()]),
                'plCategoriesUrl' => $this->generateUrl('pl_category_index'),
                'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('budget_cells')->getValue(),
            ],
        ]);
    }
}
