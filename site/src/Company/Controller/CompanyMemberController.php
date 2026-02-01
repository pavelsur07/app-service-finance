<?php

namespace App\Company\Controller;

use App\Company\Entity\Company;
use App\Company\Form\CompanyInviteOperatorType;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CompanyRepository;
use App\Company\Service\CompanyInviteManager;
use App\Notification\DTO\EmailMessage;
use App\Notification\DTO\NotificationContext;
use App\Notification\Service\NotificationRouter;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/company')]
class CompanyMemberController extends AbstractController
{
    #[Route('/users', name: 'company_users_index', methods: ['GET'])]
    public function index(
        ActiveCompanyService $activeCompanyService,
        CompanyMemberRepository $memberRepository,
        CompanyInviteRepository $inviteRepository,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertCompanyMemberAccess($company, $memberRepository);

        $now = new \DateTimeImmutable();
        $members = $memberRepository->findByCompany($company);
        $pendingInvites = $inviteRepository->findPendingByCompany($company, $now);
        $invites = $inviteRepository->findBy(['company' => $company], ['createdAt' => 'DESC']);
        $nonPendingInvites = array_values(array_filter(
            $invites,
            static fn ($invite) => !$invite->isPending($now),
        ));

        return $this->render('company/company_member/index.html.twig', [
            'company' => $company,
            'members' => $members,
            'pendingInvites' => $pendingInvites,
            'nonPendingInvites' => $nonPendingInvites,
            'inviteForm' => $this->createForm(CompanyInviteOperatorType::class)->createView(),
            'isOwner' => $company->getUser() === $this->getUser(),
        ]);
    }

    #[Route('/users/invite', name: 'company_users_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyInviteManager $inviteManager,
        NotificationRouter $notifier,
        LoggerInterface $logger,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(CompanyInviteOperatorType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();
            $result = $inviteManager->inviteOperator($company, $email, $user);

            if ($result->plainToken) {
                $this->addFlash('invite_token', $result->plainToken);
            }

            if ($result->invite && $result->plainToken) {
                $inviteUrl = $this->generateUrl('company_invite_show', [
                    'token' => $result->plainToken,
                ], UrlGeneratorInterface::ABSOLUTE_URL);
                $subject = sprintf('Приглашение в компанию "%s"', $company->getName());
                $vars = [
                    'company_name' => $company->getName(),
                    'invite_url' => $inviteUrl,
                    'expires_at' => $result->invite->getExpiresAt(),
                    'invited_email' => $result->invite->getEmail(),
                ];

                $message = new EmailMessage(
                    to: $result->invite->getEmail(),
                    subject: $subject,
                    htmlTemplate: 'notifications/email/company_invite.html.twig',
                    textTemplate: 'notifications/email/company_invite.txt.twig',
                    vars: $vars,
                );

                $ctx = new NotificationContext(
                    companyId: (string) $company->getId(),
                    locale: $request->getLocale(),
                    idempotencyKey: sprintf('company_invite:%s', $result->invite->getId()),
                );

                $sent = false;
                try {
                    if ($notifier->send('email', $message, $ctx)) {
                        $sent = true;
                    } else {
                        $logger->warning('Company invite email not sent', [
                            'companyId' => (string) $company->getId(),
                            'inviteId' => $result->invite->getId(),
                            'email' => $result->invite->getEmail(),
                        ]);
                    }
                } catch (\Throwable $exception) {
                    $logger->warning('Company invite email failed', [
                        'companyId' => (string) $company->getId(),
                        'inviteId' => $result->invite->getId(),
                        'email' => $result->invite->getEmail(),
                        'exception' => $exception,
                    ]);
                }

                if (!$sent) {
                    $this->addFlash('warning', 'Не удалось отправить письмо с приглашением.');
                }
            }

            $this->addFlash('success', 'Приглашение отправлено.');
        } else {
            $this->addFlash('danger', 'Не удалось отправить приглашение.');
        }

        return $this->redirectToRoute('company_users_index');
    }

    #[Route('/invites/{inviteId}/revoke', name: 'company_invite_revoke', methods: ['POST'])]
    public function revokeInvite(
        string $inviteId,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyInviteRepository $inviteRepository,
        CompanyInviteManager $inviteManager,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('invite_revoke_'.$inviteId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $invite = $inviteRepository->find($inviteId);
        if (!$invite || $invite->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $inviteManager->revokeInvite($invite, $user);
        $this->addFlash('success', 'Приглашение отозвано.');

        return $this->redirectToRoute('company_users_index');
    }

    #[Route('/users/{memberId}/disable', name: 'company_member_disable', methods: ['POST'])]
    public function disableMember(
        string $memberId,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyMemberRepository $memberRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);

        if (!$this->isCsrfTokenValid('member_disable_'.$memberId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $member = $memberRepository->find($memberId);
        if (!$member || $member->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $member->disable();
        $entityManager->flush();
        $this->addFlash('success', 'Участник отключен.');

        return $this->redirectToRoute('company_users_index');
    }

    #[Route('/users/{memberId}/enable', name: 'company_member_enable', methods: ['POST'])]
    public function enableMember(
        string $memberId,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyMemberRepository $memberRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);

        if (!$this->isCsrfTokenValid('member_enable_'.$memberId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $member = $memberRepository->find($memberId);
        if (!$member || $member->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $member->enable();
        $entityManager->flush();
        $this->addFlash('success', 'Участник активирован.');

        return $this->redirectToRoute('company_users_index');
    }

    #[Route('/{companyId}/users', name: 'company_users_index_legacy', methods: ['GET'])]
    public function legacyIndex(
        string $companyId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
        ActiveCompanyService $activeCompanyService,
        CompanyInviteRepository $inviteRepository,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->index($activeCompanyService, $memberRepository, $inviteRepository);
    }

    #[Route('/{companyId}/users/invite', name: 'company_users_invite_legacy', methods: ['POST'])]
    public function legacyInvite(
        string $companyId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
        ActiveCompanyService $activeCompanyService,
        CompanyInviteManager $inviteManager,
        NotificationRouter $notifier,
        LoggerInterface $logger,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->invite($request, $activeCompanyService, $inviteManager, $notifier, $logger);
    }

    #[Route('/{companyId}/invites/{inviteId}/revoke', name: 'company_invite_revoke_legacy', methods: ['POST'])]
    public function legacyRevoke(
        string $companyId,
        string $inviteId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
        ActiveCompanyService $activeCompanyService,
        CompanyInviteRepository $inviteRepository,
        CompanyInviteManager $inviteManager,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->revokeInvite($inviteId, $request, $activeCompanyService, $inviteRepository, $inviteManager);
    }

    #[Route('/{companyId}/users/{memberId}/disable', name: 'company_member_disable_legacy', methods: ['POST'])]
    public function legacyDisable(
        string $companyId,
        string $memberId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
        ActiveCompanyService $activeCompanyService,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->disableMember(
            $memberId,
            $request,
            $activeCompanyService,
            $memberRepository,
            $entityManager,
        );
    }

    #[Route('/{companyId}/users/{memberId}/enable', name: 'company_member_enable_legacy', methods: ['POST'])]
    public function legacyEnable(
        string $companyId,
        string $memberId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
        ActiveCompanyService $activeCompanyService,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->enableMember(
            $memberId,
            $request,
            $activeCompanyService,
            $memberRepository,
            $entityManager,
        );
    }

    private function activateLegacyCompany(
        string $companyId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
    ): Company {
        $company = $companyRepository->find($companyId);
        if (!$company) {
            throw $this->createNotFoundException();
        }

        $this->assertCompanyMemberAccess($company, $memberRepository);
        $request->getSession()->set('active_company_id', $company->getId());

        return $company;
    }

    private function assertCompanyMemberAccess(Company $company, CompanyMemberRepository $memberRepository): void
    {
        $user = $this->getUser();
        if (!$user) {
            throw new AccessDeniedException();
        }

        if ($company->getUser() === $user) {
            return;
        }

        $member = $memberRepository->findOneByCompanyAndUser($company, $user);
        if (!$member) {
            throw new AccessDeniedException();
        }
    }

    private function assertOwner(Company $company): void
    {
        if ($company->getUser() !== $this->getUser()) {
            throw new AccessDeniedException('Only company owner can manage members.');
        }
    }
}
