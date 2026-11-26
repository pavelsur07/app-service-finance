<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\OneC\Application\DisableConnectionAction;
use App\OneC\Exception\ConnectionNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DisableConnectionController extends AbstractController
{
    #[Route('/onec/connections/{id}/disable', name: 'onec_connection_disable', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::ADMIN_WRITE)]
    public function __invoke(string $id, Request $request, #[CurrentUser] User $user, ActiveCompanyService $activeCompany, DisableConnectionAction $disable): Response
    {
        if (!$this->isCsrfTokenValid('onec_disable_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $disable($id, (string) $user->getId(), (string) $activeCompany->getActiveCompany()->getId());
        } catch (ConnectionNotFoundException) {
            throw $this->createNotFoundException();
        }
        $this->addFlash('success', 'Подключение отключено, токен отозван.');

        return $this->redirectToRoute('onec_connection_card', ['id' => $id]);
    }
}
