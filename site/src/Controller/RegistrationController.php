<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        Security $security,
        EntityManagerInterface $entityManager,
        MessageBusInterface $bus,
    ): Response {
        $user = new User(id: Uuid::uuid4()->toString());
        $form = $this->createForm(RegistrationFormType::class, $user, [
            // Можно подключить bootstrap-тему, если используешь form_row/label/widget:
            // 'attr' => ['class' => 'needs-validation', 'novalidate' => 'novalidate'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = (string) $form->get('plainPassword')->getData();

            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            $user->setRoles(['ROLE_COMPANY_OWNER']);

            $entityManager->persist($user);

            $company = new Company(Uuid::uuid4()->toString(), $user);
            $company->setName('Новая компания');
            $user->addCompany($company);
            $entityManager->persist($company);

            $entityManager->flush();
            $createdAt = new \DateTimeImmutable();
            $bus->dispatch(new SendRegistrationEmailMessage(
                userId: $user->getId(),
                companyId: $company->getId(),
                createdAt: $createdAt,
            ));

            // Мгновенный логин
            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
