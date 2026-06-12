<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AdminUserCreateAccountTest extends WebTestCaseBase
{
    public function testNonAdminCannotCreateAccount(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $nonAdmin = UserBuilder::aUser()
            ->withEmail('owner@example.test')
            ->asCompanyOwner()
            ->build();

        $em = $this->em();
        $em->persist($nonAdmin);
        $em->flush();

        $client->loginUser($nonAdmin, 'admin');
        $client->request('POST', '/admin/users/new-account', [
            'admin_account_create' => [
                'email' => 'created-by-non-admin@example.test',
                'plainPassword' => 'secret-password',
                'companyName' => 'Forbidden Company',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, $em->getRepository(User::class)->count([]));
        self::assertSame(0, $em->getRepository(Company::class)->count([]));
        self::assertSame(0, $em->getRepository(CompanyMember::class)->count([]));
    }

    public function testAdminSeesAddAccountButton(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->persistAdmin();

        $client->loginUser($admin, 'admin');
        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button[data-bs-target="#account-create-modal"]', 'Добавить аккаунт');
    }

    public function testValidPostCreatesUserCompanyAndOwnerCompanyMemberWithoutAdminRole(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->persistAdmin();
        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', '/admin/users');

        $form = $crawler->selectButton('Создать аккаунт')->form([
            'admin_account_create[email]' => '  Owner.Account@Example.Test  ',
            'admin_account_create[plainPassword]' => 'secret-password',
            'admin_account_create[companyName]' => '  ООО Новая компания  ',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $em = $this->em();
        $userRepository = $em->getRepository(User::class);
        $createdUser = $userRepository->findOneBy(['email' => 'owner.account@example.test']);

        self::assertSame(2, $userRepository->count([]));
        self::assertNotNull($createdUser);
        self::assertSame('owner.account@example.test', $createdUser->getEmail());
        self::assertContains('ROLE_COMPANY_OWNER', $createdUser->getRoles());
        self::assertNotContains('ROLE_ADMIN', $createdUser->getRoles());

        $companyRepository = $em->getRepository(Company::class);
        $company = $companyRepository->findOneBy(['user' => $createdUser]);

        self::assertSame(1, $companyRepository->count([]));
        self::assertNotNull($company);
        self::assertSame('ООО Новая компания', $company->getName());
        self::assertSame($createdUser->getId(), $company->getUser()?->getId());

        $memberRepository = $em->getRepository(CompanyMember::class);
        $member = $memberRepository->findOneByCompanyAndUser($company, $createdUser);

        self::assertSame(1, $memberRepository->count([]));
        self::assertNotNull($member);
        self::assertSame($company->getId(), $member->getCompany()->getId());
        self::assertSame($createdUser->getId(), $member->getUser()->getId());
        self::assertSame(CompanyMember::ROLE_OWNER, $member->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());
    }

    public function testDuplicateNormalizedEmailReturnsFormErrorAndDoesNotCreateSecondCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->persistAdmin();
        $client->loginUser($admin, 'admin');

        $this->submitAccountCreateForm($client, 'Unique.Owner@Example.Test', 'ООО Первая компания');
        self::assertResponseRedirects('/admin/users');

        $crawler = $client->request('GET', '/admin/users');
        $form = $crawler->selectButton('Создать аккаунт')->form([
            'admin_account_create[email]' => '  unique.owner@example.test  ',
            'admin_account_create[plainPassword]' => 'secret-password',
            'admin_account_create[companyName]' => 'ООО Вторая компания',
        ]);

        $client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.invalid-feedback', 'There is already an account with this email');
        self::assertStringContainsString(
            'window.bootstrap.Modal.getOrCreateInstance(modalElement).show();',
            $client->getResponse()->getContent(),
        );

        $em = $this->em();
        self::assertSame(2, $em->getRepository(User::class)->count([]));
        self::assertSame(1, $em->getRepository(User::class)->count(['email' => 'unique.owner@example.test']));
        self::assertSame(1, $em->getRepository(Company::class)->count([]));
        self::assertNull($em->getRepository(Company::class)->findOneBy(['name' => 'ООО Вторая компания']));
        self::assertSame(1, $em->getRepository(CompanyMember::class)->count([]));
    }

    private function persistAdmin(): User
    {
        $admin = UserBuilder::aUser()
            ->withEmail('admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();

        $em = $this->em();
        $em->persist($admin);
        $em->flush();

        return $admin;
    }

    private function submitAccountCreateForm(KernelBrowser $client, string $email, string $companyName): void
    {
        $crawler = $client->request('GET', '/admin/users');
        $form = $crawler->selectButton('Создать аккаунт')->form([
            'admin_account_create[email]' => $email,
            'admin_account_create[plainPassword]' => 'secret-password',
            'admin_account_create[companyName]' => $companyName,
        ]);

        $client->submit($form);
    }
}
