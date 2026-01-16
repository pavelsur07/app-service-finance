<?php

namespace App\Admin\Controller;

use App\Admin\Service\UserDeletionService;
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
        $lastWeek = new \DateTimeImmutable('-7 days');

        return $this->render('admin/users/index.html.twig', [
            'title' => 'Зарегистрированные пользователи',
            'users' => $userRepository->getRegisteredUsers(),
            'totalUsers' => $userRepository->countRegisteredUsers(),
            'recentUsers' => $userRepository->countRegisteredUsersSince($lastWeek),
        ]);
    }

    #[Route('/{id}/roles', name: 'update_roles', methods: ['GET', 'POST'])]
    public function updateRoles(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            throw $this->createAccessDeniedException('Вы не можете изменить собственные права.');
        }

        if ($request->isMethod('POST')) {
            $csrfToken = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('admin_user_roles_'.$user->getId(), $csrfToken)) {
                throw $this->createAccessDeniedException('Недействительный CSRF токен.');
            }

            $selectedRole = (string) $request->request->get('role', '');
            if ('' !== $selectedRole && !array_key_exists($selectedRole, self::ROLE_LABELS)) {
                $this->addFlash('error', 'Выбрана неизвестная роль.');

                return $this->redirectToRoute('admin_user_update_roles', ['id' => $user->getId()]);
            }

            $normalizedRoles = '' === $selectedRole ? [] : [$selectedRole];

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

    /*
    #[Route('/{id}/delete', name: 'delete', methods: ['GET', 'POST'])]
    public function delete(
        User $user,
        Request $request,
        UserDeletionService $userDeletionService
    ): Response {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            throw $this->createAccessDeniedException('Вы не можете удалить собственную учётную запись.');
        }

        $enteredEmail = '';
        $userEmail = (string) $user->getEmail();

        if ($request->isMethod('POST')) {
            $csrfToken = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), $csrfToken)) {
                throw $this->createAccessDeniedException('Недействительный CSRF токен.');
            }

            $enteredEmail = trim((string) $request->request->get('email', ''));

            if ($enteredEmail === '') {
                $this->addFlash('error', 'Введите email пользователя для подтверждения удаления.');
            } elseif (mb_strtolower($enteredEmail) !== mb_strtolower($userEmail)) {
                $this->addFlash('error', 'Введённый email не совпадает с email пользователя. Удаление отменено.');
            } else {
                $userDeletionService->deleteUser($user);

                $this->addFlash('success', sprintf('Пользователь %s и связанные с ним данные удалены.', $userEmail));

                return $this->redirectToRoute('admin_user_index');
            }
        }

        return $this->render('admin/users/delete.html.twig', [
            'user' => $user,
            'enteredEmail' => $enteredEmail,
            'userEmail' => $userEmail,
        ]);
    }
    */
}
