<?php

namespace App\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Form\RegistrationFormType;
use App\Company\Repository\CompanyInviteRepository;
use App\Company\Service\CompanyInviteManager;
use App\Company\Service\InviteTokenService;
use App\Message\SendRegistrationEmailMessage;
use App\Shared\Service\RateLimiter\RegistrationRateLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    private const GENERIC_REG_ERROR = 'Не удалось создать аккаунт. Попробуйте позже.';

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        Security $security,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
        RegistrationRateLimiter $registrationRateLimiter,
        CompanyInviteManager $inviteManager,
        CompanyInviteRepository $inviteRepository,
        InviteTokenService $tokenService,
    ): Response {
        $user = new User(id: Uuid::uuid4()->toString());
        $inviteToken = $request->query->get('invite');
        $inviteToken = \is_string($inviteToken) ? \trim($inviteToken) : null;
        $isInvite = $inviteToken !== null && $inviteToken !== '';

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

            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            if ($isInvite) {
                $user->setRoles(['ROLE_COMPANY_USER']);
            } else {
                $user->setRoles(['ROLE_COMPANY_OWNER']);
            }

            $entityManager->persist($user);

            if ($isInvite) {
                $tokenHash = $tokenService->hashToken($inviteToken);
                $invite = $inviteRepository->findOneByTokenHash($tokenHash);
                if (!$invite) {
                    throw $this->createNotFoundException();
                }

                $companyId = $invite->getCompany()->getId();
                $inviteManager->acceptInvite($inviteToken, $user);
                $request->getSession()->set('active_company_id', $companyId);
            } else {
                $company = new Company(Uuid::uuid4()->toString(), $user);
                $companyName = trim((string) $form->get('companyName')->getData());
                $company->setName($companyName);
                $user->addCompany($company);
                $entityManager->persist($company);

                $entityManager->flush();
                $createdAt = new \DateTimeImmutable();
                $bus->dispatch(new SendRegistrationEmailMessage(
                    userId: $user->getId(),
                    companyId: $company->getId(),
                    createdAt: $createdAt,
                ));
            }

            // Мгновенный логин
            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
