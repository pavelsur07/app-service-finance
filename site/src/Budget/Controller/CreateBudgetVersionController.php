<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\CreateBudgetVersionAction;
use App\Budget\Application\DTO\CreateBudgetVersionCommand;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Создать вариант: пустой на год или копию варианта. Скрытый `versionId` — ключ повтора.
 * POST /finance/budget/versions.
 */
#[Route('/finance/budget/versions', name: 'budget_version_create', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class CreateBudgetVersionController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CreateBudgetVersionAction $createVersion,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $year = $request->request->getInt('year');
        $back = $this->redirectToRoute('budget_index', ['year' => $year]);
        if (!$this->isCsrfTokenValid('budget_version_create', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела — обновите страницу.');

            return $back;
        }

        $versionId = (string) $request->request->get('versionId');
        $copyFrom = trim((string) $request->request->get('copyFrom'));
        if (!Uuid::isValid($versionId)) {
            $this->addFlash('danger', 'Форма устарела — обновите страницу.');

            return $back;
        }

        try {
            $id = ($this->createVersion)(new CreateBudgetVersionCommand(
                $versionId,
                $companyId,
                (string) $request->request->get('name'),
                year: '' === $copyFrom ? $year : null,
                copyFromVersionId: '' === $copyFrom ? null : $copyFrom,
            ));
        } catch (InvalidBudgetInputException|BudgetVersionNotFoundException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $back;
        }

        return $this->redirectToRoute('budget_version_show', ['id' => $id]);
    }
}
