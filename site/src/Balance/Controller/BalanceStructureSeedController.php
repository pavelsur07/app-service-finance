<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceStructureSeedController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly BalanceAccess $access, private readonly SeedBalanceStructureAction $seed)
    {
    }

    #[Route('/balance/structure/seed', name: 'balance_structure_seed', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        if (!$this->isCsrfTokenValid('balance_seed', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $created = ($this->seed)($companyId, $actor);
        $this->addFlash('success', $created ? 'Базовая структура создана.' : 'Структура уже существует и сохранена без изменений.');

        return $this->redirectToRoute('balance_structure_index');
    }
}
