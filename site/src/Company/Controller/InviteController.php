<?php

namespace App\Company\Controller;

use App\Company\Repository\CompanyInviteRepository;
use App\Company\Service\CompanyInviteManager;
use App\Company\Service\InviteTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InviteController extends AbstractController
{
    #[Route('/invite/{token}', name: 'company_invite_show', methods: ['GET'])]
    public function show(
        string $token,
        CompanyInviteRepository $inviteRepository,
        InviteTokenService $tokenService,
    ): Response {
        $invite = $this->findInviteByToken($token, $inviteRepository, $tokenService);

        return $this->render('company/company_member/invite_show.html.twig', [
            'invite' => $invite,
            'token' => $token,
        ]);
    }

    #[Route('/invite/{token}/accept', name: 'company_invite_accept', methods: ['POST'])]
    public function accept(
        string $token,
        Request $request,
        CompanyInviteRepository $inviteRepository,
        InviteTokenService $tokenService,
        CompanyInviteManager $inviteManager,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $invite = $this->findInviteByToken($token, $inviteRepository, $tokenService);

        if (!$this->isCsrfTokenValid('invite_accept_'.$invite->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $companyId = $invite->getCompany()->getId();

        $inviteManager->acceptInvite($token, $user);
        $request->getSession()->set('active_company_id', $companyId);

        return $this->redirectToRoute('app_home_index');
    }

    private function findInviteByToken(
        string $token,
        CompanyInviteRepository $inviteRepository,
        InviteTokenService $tokenService,
    ): \App\Company\Entity\CompanyInvite {
        $tokenHash = $tokenService->hashToken($token);
        $invite = $inviteRepository->findOneByTokenHash($tokenHash);
        if (!$invite) {
            throw $this->createNotFoundException();
        }

        return $invite;
    }
}
