<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\OneC\Application\SetOrganizationExportAction;
use App\OneC\Exception\ConnectionNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SetOrganizationExportController extends AbstractController
{
    #[Route('/onec/connections/{id}/organizations/{guid}/export', name: 'onec_organization_export', requirements: ['id' => Requirement::UUID, 'guid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(string $id, string $guid, Request $request, #[CurrentUser] User $user, ActiveCompanyService $activeCompany, SetOrganizationExportAction $setExport): Response
    {
        if (!$this->isCsrfTokenValid('onec_export_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $setExport($id, (string) $activeCompany->getActiveCompany()->getId(), $guid, $request->request->has('allowed'), (string) $user->getId());
        } catch (ConnectionNotFoundException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('onec_connection_card', ['id' => $id]);
    }
}
