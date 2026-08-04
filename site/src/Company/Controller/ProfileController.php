<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Entity\User;
use App\Company\Exception\InvalidCurrentPasswordException;
use App\Company\Exception\SamePasswordException;
use App\Company\Form\ChangePasswordFormType;
use App\Company\Service\PasswordChanger;
use App\Shared\Service\RateLimiter\PasswordChangeRateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProfileController extends AbstractController
{
    private const GENERIC_RATE_LIMIT_ERROR = 'Слишком много попыток. Попробуйте позже.';
    private const GENERIC_CHANGE_ERROR = 'Не удалось изменить пароль. Попробуйте позже.';

    #[Route('/profile/password', name: 'app_profile_password', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(
        Request $request,
        PasswordChanger $passwordChanger,
        PasswordChangeRateLimiter $passwordChangeRateLimiter,
        LoggerInterface $logger,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Honeypot: показываем generic-ошибку, чтобы не обучать ботов
            if ($form->get('website')->getData()) {
                $form->addError(new FormError(self::GENERIC_CHANGE_ERROR));

                return $this->render('security/change_password.html.twig', [
                    'form' => $form,
                ]);
            }

            if ($form->isValid()) {
                // Лимит на аккаунт (не на IP): иначе угнавший сессию атакующий
                // получал бы неограниченный перебор текущего пароля сменой IP
                if (!$passwordChangeRateLimiter->consume((string) $user->getId())) {
                    $form->addError(new FormError(self::GENERIC_RATE_LIMIT_ERROR));

                    return $this->render('security/change_password.html.twig', [
                        'form' => $form,
                    ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
                }

                /** @var string $currentPassword */
                $currentPassword = (string) $form->get('currentPassword')->getData();
                /** @var string $newPassword */
                $newPassword = (string) $form->get('plainPassword')->getData();

                try {
                    $passwordChanger->change($user, $currentPassword, $newPassword);

                    // Регенерируем session id: текущая сессия сохраняется,
                    // старые украденные cookie перестают работать
                    $request->getSession()->migrate(true);

                    // Успешная смена не должна сжигать лимит: лимит — защита от перебора.
                    // Best-effort: сброс лимитера не должен ломать завершённую смену пароля
                    try {
                        $passwordChangeRateLimiter->reset((string) $user->getId());
                    } catch (\Throwable $e) {
                        $logger->warning('Password change rate limiter reset failed', [
                            'userId' => $user->getId(),
                            'exception' => $e,
                        ]);
                    }

                    $this->addFlash('success', 'Пароль изменён');

                    return $this->redirectToRoute('app_profile_password');
                } catch (InvalidCurrentPasswordException) {
                    // Трассировка перебора: всплеск неудачных попыток с последующим успехом —
                    // повод для алерта безопасности
                    $logger->warning('Password change failed: invalid current password', [
                        'userId' => $user->getId(),
                    ]);
                    $form->get('currentPassword')->addError(new FormError('Текущий пароль указан неверно'));
                } catch (SamePasswordException) {
                    $form->get('plainPassword')->addError(new FormError('Новый пароль должен отличаться от текущего'));
                }
            }
        }

        return $this->render('security/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
