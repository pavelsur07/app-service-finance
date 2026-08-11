<?php

namespace App\Company\Controller;

use App\Company\Application\AssignCompanyMemberAccessRoleAction;
use App\Company\Application\DisableCompanyMemberAction;
use App\Company\Application\EnableCompanyMemberAction;
use App\Company\Domain\Service\CompanyAdminWriteGuard;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Exception\CompanyRoleNotAvailableException;
use App\Company\Exception\LastCompanyAdminException;
use App\Company\Form\CompanyInviteOperatorType;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CompanyRoleRepository;
use App\Company\Security\SystemCompanyRoles;
use App\Company\Service\CompanyInviteManager;
use App\Notification\DTO\EmailMessage;
use App\Notification\DTO\NotificationContext;
use App\Notification\Service\NotificationRouter;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/company')]
class CompanyMemberController extends AbstractController
{
    public function __construct(
        private readonly CompanyAdminWriteGuard $adminWriteGuard,
    ) {
    }

    #[Route('/users', name: 'company_users_index', methods: ['GET'])]
    public function index(
        ActiveCompanyService $activeCompanyService,
        CompanyMemberRepository $memberRepository,
        CompanyInviteRepository $inviteRepository,
        CompanyRoleRepository $roleRepository,
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
        $user = $this->getUser();
        $isOwner = $user instanceof User && $company->getUser()->getId() === $user->getId();

        return $this->render('company/company_member/index.html.twig', [
            'company' => $company,
            'members' => $members,
            'pendingInvites' => $pendingInvites,
            'nonPendingInvites' => $nonPendingInvites,
            'inviteForm' => $this->createInviteForm($company, $roleRepository)->createView(),
            'availableRoles' => $this->resolveAvailableRoles($company, $roleRepository),
            'isOwner' => $isOwner,
        ]);
    }

    #[Route('/users/invite', name: 'company_users_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyInviteManager $inviteManager,
        CompanyRoleRepository $roleRepository,
        NotificationRouter $notifier,
        LoggerInterface $logger,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createInviteForm($company, $roleRepository);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();
            $accessRole = $form->get('accessRole')->getData();
            if (!$accessRole instanceof CompanyRole) {
                $accessRole = $roleRepository->find(SystemCompanyRoles::FULL_ACCESS_ID);
            }
            try {
                $result = $inviteManager->inviteOperator(
                    company: $company,
                    email: $email,
                    actor: $user,
                    accessRole: $accessRole,
                );
            } catch (CompanyRoleNotAvailableException) {
                // Шаблон удалили между выбором в форме и записью приглашения.
                $this->addFlash('danger', 'Выбранный шаблон больше недоступен.');

                return $this->redirectToRoute('company_users_index');
            }

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
        DisableCompanyMemberAction $disableCompanyMember,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();

        if (!$this->isCsrfTokenValid('member_disable_'.$memberId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $disableCompanyMember((string) $company->getId(), $memberId, $user);
        $this->addFlash('success', 'Участник отключен.');

        return $this->redirectToRoute('company_users_index');
    }

    #[Route('/users/{memberId}/enable', name: 'company_member_enable', methods: ['POST'])]
    public function enableMember(
        string $memberId,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        EnableCompanyMemberAction $enableCompanyMember,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();

        if (!$this->isCsrfTokenValid('member_enable_'.$memberId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $enableCompanyMember((string) $company->getId(), $memberId, $user);
        $this->addFlash('success', 'Участник активирован.');

        return $this->redirectToRoute('company_users_index');
    }

    #[Route('/users/{memberId}/access-role', name: 'company_member_access_role', methods: ['POST'])]
    public function setAccessRole(
        string $memberId,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyMemberRepository $memberRepository,
        CompanyRoleRepository $roleRepository,
        AssignCompanyMemberAccessRoleAction $assignAccessRole,
        LoggerInterface $logger,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);

        if (!$this->isCsrfTokenValid('member_access_role_'.$memberId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $member = $memberRepository->findOneByIdAndCompanyId($memberId, (string) $company->getId());
        if (!$member instanceof CompanyMember) {
            throw $this->createNotFoundException();
        }

        if (CompanyMember::ROLE_OWNER === $member->getRole()) {
            $this->addFlash('danger', 'Роль владельца нельзя изменить через шаблон.');

            return $this->redirectToRoute('company_users_index');
        }

        $roleId = (string) $request->request->get('roleId');
        $newRole = $roleRepository->find($roleId);
        if (!$newRole instanceof CompanyRole
            || SystemCompanyRoles::OWNER_ID === (string) $newRole->getId()
            || !$this->roleBelongsToCompanyOrSystem($newRole, $company)
        ) {
            $this->addFlash('danger', 'Выбранный шаблон не найден или недоступен.');

            return $this->redirectToRoute('company_users_index');
        }

        if (!$this->adminWriteGuard->keepsAdminWriteAfterMemberChange($company, (string) $member->getId(), $newRole->getPermissions())) {
            $this->addFlash('danger', 'Нельзя снять последний административный доступ у компании.');

            return $this->redirectToRoute('company_users_index');
        }

        $oldRole = $member->getAccessRole();

        try {
            $assignAccessRole($member, $newRole);
        } catch (LastCompanyAdminException) {
            // Конкурентное понижение между проверкой выше и записью под блокировкой.
            $this->addFlash('danger', 'Нельзя снять последний административный доступ у компании.');

            return $this->redirectToRoute('company_users_index');
        } catch (CompanyRoleNotAvailableException) {
            // Шаблон удалили между проверкой выше и назначением — отказ пришёл от FK.
            $this->addFlash('danger', 'Выбранный шаблон больше недоступен.');

            return $this->redirectToRoute('company_users_index');
        }

        $logger->info('Company member access role changed', [
            'companyId' => (string) $company->getId(),
            'userId' => (string) $this->requireUser()->getId(),
            'memberId' => (string) $member->getId(),
            'oldRoleId' => null !== $oldRole ? (string) $oldRole->getId() : null,
            'newRoleId' => (string) $newRole->getId(),
        ]);

        $this->addFlash('success', 'Шаблон доступа участника обновлён.');

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
        CompanyRoleRepository $roleRepository,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->index($activeCompanyService, $memberRepository, $inviteRepository, $roleRepository);
    }

    #[Route('/{companyId}/users/invite', name: 'company_users_invite_legacy', methods: ['POST'])]
    public function legacyInvite(
        string $companyId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
        ActiveCompanyService $activeCompanyService,
        CompanyInviteManager $inviteManager,
        CompanyRoleRepository $roleRepository,
        NotificationRouter $notifier,
        LoggerInterface $logger,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->invite($request, $activeCompanyService, $inviteManager, $roleRepository, $notifier, $logger);
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
        DisableCompanyMemberAction $disableCompanyMember,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->disableMember(
            $memberId,
            $request,
            $activeCompanyService,
            $disableCompanyMember,
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
        EnableCompanyMemberAction $enableCompanyMember,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->enableMember(
            $memberId,
            $request,
            $activeCompanyService,
            $enableCompanyMember,
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
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        if ($company->getUser()->getId() === $user->getId()) {
            return;
        }

        $member = $memberRepository->findActiveOneByCompanyAndUser($company, $user);
        if (!$member) {
            throw new AccessDeniedException();
        }
    }

    private function assertOwner(Company $company): void
    {
        $user = $this->getUser();
        if (!$user instanceof User || $company->getUser()->getId() !== $user->getId()) {
            throw new AccessDeniedException('Only company owner can manage members.');
        }
    }

    #[Route('/{companyId}/users/{memberId}/access-role', name: 'company_member_access_role_legacy', methods: ['POST'])]
    public function legacySetAccessRole(
        string $companyId,
        string $memberId,
        Request $request,
        CompanyRepository $companyRepository,
        CompanyMemberRepository $memberRepository,
        ActiveCompanyService $activeCompanyService,
        CompanyRoleRepository $roleRepository,
        AssignCompanyMemberAccessRoleAction $assignAccessRole,
        LoggerInterface $logger,
    ): Response {
        $this->activateLegacyCompany($companyId, $request, $companyRepository, $memberRepository);

        return $this->setAccessRole(
            $memberId,
            $request,
            $activeCompanyService,
            $memberRepository,
            $roleRepository,
            $assignAccessRole,
            $logger,
        );
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function createInviteForm(Company $company, CompanyRoleRepository $roleRepository): \Symfony\Component\Form\FormInterface
    {
        return $this->createForm(CompanyInviteOperatorType::class, null, [
            'company' => $company,
            'full_access_role' => $roleRepository->find(SystemCompanyRoles::FULL_ACCESS_ID),
        ]);
    }

    /**
     * @return list<CompanyRole>
     */
    private function resolveAvailableRoles(Company $company, CompanyRoleRepository $roleRepository): array
    {
        return $roleRepository->findAssignableForCompany($company);
    }

    private function roleBelongsToCompanyOrSystem(CompanyRole $role, Company $company): bool
    {
        $roleCompany = $role->getCompany();

        return null === $roleCompany || (string) $roleCompany->getId() === (string) $company->getId();
    }
}
