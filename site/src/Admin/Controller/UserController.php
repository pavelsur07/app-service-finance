<?php

namespace App\Admin\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/users', name: 'admin_user_')]
final class UserController extends AbstractController
{
    /**
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        'ROLE_ADMIN' => 'Администратор',
        'ROLE_COMPANY_OWNER' => 'Владелец компании',
        'ROLE_COMPANY_USER' => 'Сотрудник компании',
    ];

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'title' => 'Зарегистрированные пользователи',
            'users' => $userRepository->getRegisteredUsers(),
        ]);
    }

    #[Route('/{id}/roles', name: 'update_roles', methods: ['GET', 'POST'])]
    public function updateRoles(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            throw $this->createAccessDeniedException('Вы не можете изменить собственные права.');
        }

        if ($request->isMethod('POST')) {
            $csrfToken = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('admin_user_roles_' . $user->getId(), $csrfToken)) {
                throw $this->createAccessDeniedException('Недействительный CSRF токен.');
            }

            $selectedRole = (string) $request->request->get('role', '');
            if ($selectedRole !== '' && !array_key_exists($selectedRole, self::ROLE_LABELS)) {
                $this->addFlash('error', 'Выбрана неизвестная роль.');

                return $this->redirectToRoute('admin_user_update_roles', ['id' => $user->getId()]);
            }

            $normalizedRoles = $selectedRole === '' ? [] : [$selectedRole];

            $user->setRoles($normalizedRoles);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Права пользователя %s успешно обновлены.', $user->getEmail()));

            return $this->redirectToRoute('admin_user_index');
        }

        $selectedRole = null;
        foreach (self::ROLE_LABELS as $roleValue => $roleLabel) {
            if (in_array($roleValue, $user->getRoles(), true)) {
                $selectedRole = $roleValue;
                break;
            }
        }

        return $this->render('admin/users/update_roles.html.twig', [
            'user' => $user,
            'roleLabels' => self::ROLE_LABELS,
            'selectedRole' => $selectedRole,
        ]);
    }
}
