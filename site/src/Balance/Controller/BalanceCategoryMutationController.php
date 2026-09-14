<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceStructureService;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceCategoryMutationController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly BalanceStructureService $structure,
    ) {
    }

    #[Route('/balance/structure/{id}/delete', name: 'balance_structure_delete', defaults: ['action' => 'delete'], methods: ['POST'])]
    #[Route('/balance/structure/{id}/archive', name: 'balance_structure_archive', defaults: ['action' => 'archive'], methods: ['POST'])]
    public function __invoke(Request $request, string $id, string $action): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        if (!$this->isCsrfTokenValid('category_'.$action.'_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недействительный защитный токен.');
        }
        if ('delete' === $action) {
            $this->structure->deleteCategory($companyId, $actor, $id);
        } else {
            $this->structure->archiveCategory($companyId, $actor, $id, $request->request->getBoolean('archive', true));
        }

        return $this->redirectToRoute('balance_structure_index');
    }
}
