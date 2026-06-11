<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Company\Entity\Company;
use App\Repository\UserRepository;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\HttpFoundation\Response;

final class UserCreateAccountControllerTest extends WebTestCaseBase
{
    public function testCreateAccountRedirectsAndCreatesOwnerCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = UserBuilder::aUser()
            ->withEmail('admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();

        $em = $this->em();
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', '/admin/users');

        $form = $crawler->selectButton('Создать аккаунт')->form([
            'registration_form[email]' => 'owner@example.test',
            'registration_form[plainPassword]' => 'secret-password',
            'registration_form[companyName]' => 'ООО Новая компания',
            'registration_form[agreeTerms]' => '1',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        $createdUser = $userRepository->findOneBy(['email' => 'owner@example.test']);

        self::assertNotNull($createdUser);
        self::assertContains('ROLE_COMPANY_OWNER', $createdUser->getRoles());

        $company = $this->em()->getRepository(Company::class)->findOneBy(['user' => $createdUser]);

        self::assertNotNull($company);
        self::assertSame('ООО Новая компания', $company->getName());
        self::assertSame(
            ['Аккаунт и компания успешно созданы.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('success')
        );
    }

    public function testInvalidCreateAccountRendersUsersIndexWithModalOpen(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = UserBuilder::aUser()
            ->withEmail('admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();
        $existingUser = UserBuilder::aUser()
            ->withIndex(2)
            ->withEmail('existing@example.test')
            ->build();

        $em = $this->em();
        $em->persist($admin);
        $em->persist($existingUser);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', '/admin/users');

        $form = $crawler->selectButton('Создать аккаунт')->form([
            'registration_form[email]' => 'owner@example.test',
            'registration_form[plainPassword]' => 'secret-password',
            'registration_form[companyName]' => 'ООО Новая компания',
            'registration_form[agreeTerms]' => '1',
            'registration_form[website]' => 'spam.example',
        ]);

        $client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('h2.page-title', 'Зарегистрированные пользователи');
        self::assertStringContainsString('existing@example.test', $client->getResponse()->getContent());
        self::assertSelectorTextContains('.alert-danger', 'Не удалось создать аккаунт. Попробуйте позже.');
        self::assertStringContainsString(
            'window.bootstrap.Modal.getOrCreateInstance(modalElement).show();',
            $client->getResponse()->getContent()
        );
    }
}
