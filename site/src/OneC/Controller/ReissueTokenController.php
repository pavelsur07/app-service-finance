<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\OneC\Application\ReissueTokenAction;
use App\OneC\Exception\ConnectionNotFoundException;
use App\OneC\Http\ConnectionCardPage;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ReissueTokenController extends AbstractController
{
    #[Route('/onec/connections/{id}/reissue', name: 'onec_connection_reissue', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::ADMIN_WRITE)]
    public function __invoke(string $id, Request $request, #[CurrentUser] User $user, ActiveCompanyService $activeCompany, ReissueTokenAction $reissue, ConnectionCardPage $page): Response
    {
        if (!$this->isCsrfTokenValid('onec_reissue_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ownerId = (string) $user->getId();
        try {
            $token = $reissue($id, $ownerId, (string) $activeCompany->getActiveCompany()->getId());

            return $page->render($ownerId, $id, $token);
        } catch (ConnectionNotFoundException) {
            throw $this->createNotFoundException();
        }
    }
}
