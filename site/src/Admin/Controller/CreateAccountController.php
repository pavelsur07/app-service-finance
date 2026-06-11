<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Application\CreateAccountAction;
use App\Admin\Form\AdminAccountCreateType;
use App\Company\Entity\User;
use App\Repository\UserRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users/new-account', name: 'admin_user_create_account', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class CreateAccountController extends AbstractController
{
    public function __invoke(
        Request $request,
        UserRepository $userRepository,
        CreateAccountAction $createAccount,
    ): Response {
        $account = new User(id: Uuid::uuid7()->toString());
        $accountForm = $this->createForm(AdminAccountCreateType::class, $account);
        $accountForm->handleRequest($request);

        if (!$accountForm->isSubmitted() || !$accountForm->isValid()) {
            return $this->renderUserIndex($request, $userRepository, $accountForm);
        }

        /** @var string $plainPassword */
        $plainPassword = (string) $accountForm->get('plainPassword')->getData();
        $companyName = (string) $accountForm->get('companyName')->getData();

        $createAccount($account, $plainPassword, $companyName);

        $this->addFlash('success', 'Аккаунт и компания успешно созданы.');

        return $this->redirectToRoute('admin_user_index');
    }

    private function renderUserIndex(
        Request $request,
        UserRepository $userRepository,
        FormInterface $accountForm,
    ): Response {
        $lastWeek = new \DateTimeImmutable('-7 days');
        $page = max(1, (int) $request->query->get('page', 1));
        $pager = $userRepository->getRegisteredUsers($page);
        $users = iterator_to_array($pager->getCurrentPageResults());

        return $this->render('admin/users/index.html.twig', [
            'title' => 'Зарегистрированные пользователи',
            'users' => $users,
            'pager' => $pager,
            'totalUsers' => $userRepository->countRegisteredUsers(),
            'recentUsers' => $userRepository->countRegisteredUsersSince($lastWeek),
            'accountForm' => $accountForm,
            'showAccountModal' => true,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
