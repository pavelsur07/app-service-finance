<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Form\CompanyRoleType;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CompanyRoleRepository;
use App\Company\Security\Module;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/company/roles')]
final class CompanyRoleController extends AbstractController
{
    #[Route('', name: 'company_role_index', methods: ['GET'])]
    public function index(
        ActiveCompanyService $activeCompanyService,
        CompanyRoleRepository $roleRepository,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);

        $systemRoles = $roleRepository->findBy(['company' => null], ['name' => 'ASC']);
        $companyRoles = $roleRepository->findBy(['company' => $company], ['name' => 'ASC']);

        return $this->render('company/role/index.html.twig', [
            'company' => $company,
            'systemRoles' => $systemRoles,
            'companyRoles' => $companyRoles,
        ]);
    }

    #[Route('/new', name: 'company_role_new', methods: ['GET', 'POST'])]
    #[Route('/create', name: 'company_role_create', methods: ['POST'])]
    public function new(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyRoleRepository $roleRepository,
        LoggerInterface $logger,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);

        $role = null;
        $form = $this->createForm(CompanyRoleType::class, $role);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CompanyRole $role */
            $role = $form->getData();
            $role->setCompany($company);
            $role->setPermissions($this->collectPermissionsFromForm($form));
            $roleRepository->save($role);

            $logger->info('Company role created', [
                'companyId' => (string) $company->getId(),
                'userId' => (string) $this->requireUser()->getId(),
                'roleId' => (string) $role->getId(),
                'roleName' => $role->getName(),
                'permissions' => $role->getPermissions(),
            ]);

            $this->addFlash('success', 'Шаблон доступа создан.');

            return $this->redirectToRoute('company_role_index');
        }

        return $this->render('company/role/form.html.twig', [
            'company' => $company,
            'form' => $form->createView(),
            'role' => null,
        ]);
    }

    #[Route('/{roleId}/edit', name: 'company_role_edit', methods: ['GET', 'POST'])]
    #[Route('/{roleId}/update', name: 'company_role_update', methods: ['POST'])]
    public function edit(
        string $roleId,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyRoleRepository $roleRepository,
        LoggerInterface $logger,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);

        $role = $this->findEditableCompanyRole($roleId, $company, $roleRepository);
        if (!$role instanceof CompanyRole) {
            $this->addFlash('danger', 'Шаблон не найден или не может быть изменён.');

            return $this->redirectToRoute('company_role_index');
        }

        $oldPermissions = $role->getPermissions();

        $form = $this->createForm(CompanyRoleType::class, $role);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $role->setPermissions($this->collectPermissionsFromForm($form));
            $roleRepository->save($role);

            $logger->info('Company role updated', [
                'companyId' => (string) $company->getId(),
                'userId' => (string) $this->requireUser()->getId(),
                'roleId' => (string) $role->getId(),
                'roleName' => $role->getName(),
                'oldPermissions' => $oldPermissions,
                'newPermissions' => $role->getPermissions(),
            ]);

            $this->addFlash('success', 'Шаблон доступа обновлён.');

            return $this->redirectToRoute('company_role_index');
        }

        return $this->render('company/role/form.html.twig', [
            'company' => $company,
            'form' => $form->createView(),
            'role' => $role,
        ]);
    }

    #[Route('/{roleId}/delete', name: 'company_role_delete', methods: ['POST'])]
    public function delete(
        string $roleId,
        Request $request,
        ActiveCompanyService $activeCompanyService,
        CompanyRoleRepository $roleRepository,
        CompanyMemberRepository $memberRepository,
        CompanyInviteRepository $inviteRepository,
        LoggerInterface $logger,
    ): Response {
        $company = $activeCompanyService->getActiveCompany();
        $this->assertOwner($company);

        if (!$this->isCsrfTokenValid('delete_role_'.$roleId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $role = $this->findEditableCompanyRole($roleId, $company, $roleRepository);
        if (!$role instanceof CompanyRole) {
            $this->addFlash('danger', 'Шаблон не найден или не может быть удалён.');

            return $this->redirectToRoute('company_role_index');
        }

        $now = new \DateTimeImmutable();
        $membersCount = $memberRepository->countByAccessRole($role);
        $pendingInvitesCount = $inviteRepository->countPendingByAccessRole($role, $now);

        if ($membersCount > 0 || $pendingInvitesCount > 0) {
            $logger->info('Company role deletion rejected: in use', [
                'companyId' => (string) $company->getId(),
                'userId' => (string) $this->requireUser()->getId(),
                'roleId' => (string) $role->getId(),
                'membersCount' => $membersCount,
                'pendingInvitesCount' => $pendingInvitesCount,
            ]);
            $this->addFlash('danger', 'Нельзя удалить шаблон, назначенный участникам или активным приглашениям.');

            return $this->redirectToRoute('company_role_index');
        }

        $roleName = $role->getName();
        $roleRepository->remove($role);

        $logger->info('Company role deleted', [
            'companyId' => (string) $company->getId(),
            'userId' => (string) $this->requireUser()->getId(),
            'roleId' => (string) $roleId,
            'roleName' => $roleName,
        ]);

        $this->addFlash('success', 'Шаблон доступа удалён.');

        return $this->redirectToRoute('company_role_index');
    }

    private function assertOwner(Company $company): void
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Company\Entity\User || $company->getUser()->getId() !== $user->getId()) {
            throw new AccessDeniedException('Only company owner can manage roles.');
        }
    }

    private function requireUser(): \App\Company\Entity\User
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Company\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findEditableCompanyRole(
        string $roleId,
        Company $company,
        CompanyRoleRepository $roleRepository,
    ): ?CompanyRole {
        $role = $roleRepository->find($roleId);
        if (!$role instanceof CompanyRole) {
            return null;
        }

        $roleCompany = $role->getCompany();
        if (null === $roleCompany || (string) $roleCompany->getId() !== (string) $company->getId()) {
            return null;
        }

        return $role;
    }

    /**
     * @return array<string, string>
     */
    private function collectPermissionsFromForm(FormInterface $form): array
    {
        $permissions = [];
        $permissionsForm = $form->get('permissions');
        foreach (Module::cases() as $module) {
            $permissions[$module->value] = (string) $permissionsForm->get($module->value)->getData();
        }

        return $permissions;
    }
}
