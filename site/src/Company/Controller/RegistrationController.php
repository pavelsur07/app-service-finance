<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Entity\User;
use App\Company\Form\RegistrationFormType;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Service\CompanyInviteManager;
use App\Company\Service\CompanyOwnerAccountCreator;
use App\Company\Service\InviteTokenService;
use App\Shared\Service\RateLimiter\RegistrationRateLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    private const GENERIC_REG_ERROR = 'Не удалось создать аккаунт. Попробуйте позже.';

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        Security $security,
        EntityManagerInterface $entityManager,
        RegistrationRateLimiter $registrationRateLimiter,
        CompanyInviteManager $inviteManager,
        CompanyInviteRepository $inviteRepository,
        InviteTokenService $tokenService,
        CompanyOwnerAccountCreator $companyOwnerAccountCreator,
    ): Response {
        $user = new User(id: Uuid::uuid4()->toString());
        $inviteToken = $request->query->get('invite');
        $inviteToken = \is_string($inviteToken) ? \trim($inviteToken) : null;
        $isInvite = null !== $inviteToken && '' !== $inviteToken;

        $form = $this->createForm(RegistrationFormType::class, $user, [
            'is_invite' => $isInvite,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $ip = $request->getClientIp() ?? 'unknown';
            if (!$registrationRateLimiter->consume($ip)) {
                $form->addError(new FormError(self::GENERIC_REG_ERROR));

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }
        }

        if ($form->isSubmitted() && $form->get('website')->getData()) {
            $form->addError(new FormError(self::GENERIC_REG_ERROR));

            return $this->render('security/register.html.twig', [
                'registrationForm' => $form,
            ]);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = (string) $form->get('plainPassword')->getData();

            if ($isInvite) {
                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
                $user->setRoles(['ROLE_COMPANY_USER']);
                $entityManager->persist($user);

                $tokenHash = $tokenService->hashToken($inviteToken);
                $invite = $inviteRepository->findOneByTokenHash($tokenHash);
                if (!$invite) {
                    throw $this->createNotFoundException();
                }

                $companyId = $invite->getCompany()->getId();
                $inviteManager->acceptInvite($inviteToken, $user);
                $request->getSession()->set('active_company_id', $companyId);
            } else {
                $companyOwnerAccountCreator->create(
                    user: $user,
                    plainPassword: $plainPassword,
                    companyName: (string) $form->get('companyName')->getData(),
                    sendRegistrationEmail: true,
                );
            }

            // Мгновенный логин
            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
