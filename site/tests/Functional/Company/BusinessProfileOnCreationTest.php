<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Enum\BusinessProfile;
use App\Shared\Service\RateLimiter\RegistrationRateLimiter;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Профиль бизнеса выбирается во всех путях создания компании: регистрация,
 * `/company/new`, админка. Без значения — профиль по умолчанию.
 */
final class BusinessProfileOnCreationTest extends WebTestCaseBase
{
    public function testRegistrationAsksProfileWithMarketplacePreselected(): void
    {
        $client = $this->registrationClient();
        $crawler = $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#businessProfileLabel', 'Как вы продаёте?');
        self::assertCount(1, $crawler->filter('input[name="registration_form[businessProfile]"][value="MARKETPLACE"][checked]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[businessProfile]"][value="UNIVERSAL"]:not([checked])'));
        self::assertSelectorTextContains('label[for="registration_form_businessProfile_1"]', 'Базовый');
    }

    public function testRegistrationStoresChosenProfile(): void
    {
        $client = $this->registrationClient();
        $crawler = $client->request('GET', '/register');
        $client->submit($crawler->selectButton('Создать аккаунт')->form($this->registrationFields('universal@example.test', 'Universal LLC') + [
            'registration_form[businessProfile]' => 'UNIVERSAL',
        ]));

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(BusinessProfile::UNIVERSAL, $this->companyNamed('Universal LLC')->getBusinessProfile());
    }

    public function testRegistrationWithoutProfileValueUsesDefault(): void
    {
        $client = $this->registrationClient();
        $crawler = $client->request('GET', '/register');
        $values = $crawler->selectButton('Создать аккаунт')->form($this->registrationFields('no-profile@example.test', 'No Profile LLC'))->getPhpValues();
        // Страница, открытая до появления вопроса, присылает форму без поля.
        unset($values['registration_form']['businessProfile']);
        $client->request('POST', '/register', $values);

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame(BusinessProfile::MARKETPLACE, $this->companyNamed('No Profile LLC')->getBusinessProfile());
    }

    /**
     * Регрессия: пустое значение проходило валидацию как null и роняло регистрацию в 500
     * (TypeError в CreateCompanyOwnerAccountAction), в `/company/new` — на setBusinessProfile(null).
     */
    public function testEmptyProfileValueUsesDefault(): void
    {
        $client = $this->registrationClient();
        $crawler = $client->request('GET', '/register');
        $values = $crawler->selectButton('Создать аккаунт')->form($this->registrationFields('empty-profile@example.test', 'Empty Profile LLC'))->getPhpValues();
        $values['registration_form']['businessProfile'] = '';
        $client->request('POST', '/register', $values);

        self::assertTrue($client->getResponse()->isRedirect(), (string) $client->getResponse()->getStatusCode());
        self::assertSame(BusinessProfile::MARKETPLACE, $this->companyNamed('Empty Profile LLC')->getBusinessProfile());

        $owner = UserBuilder::aUser()->withEmail('empty-profile-owner@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $this->em()->persist($owner);
        $this->em()->flush();
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/company/new');
        $values = $crawler->selectButton('Создать')->form(['company[name]' => 'Empty Profile Co'])->getPhpValues();
        $values['company']['businessProfile'] = '';
        $client->request('POST', '/company/new', $values);

        self::assertResponseRedirects('/company/');
        self::assertSame(BusinessProfile::MARKETPLACE, $this->companyNamed('Empty Profile Co')->getBusinessProfile());
    }

    public function testUnknownProfileIsRejected(): void
    {
        $client = $this->registrationClient();
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Создать аккаунт')->form($this->registrationFields('unknown-profile@example.test', 'Unknown Profile LLC'));
        $form->disableValidation()->setValues(['registration_form[businessProfile]' => 'RETAIL']);
        $client->submit($form);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Выберите, как вы продаёте', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->em()->getRepository(Company::class)->count([]));
    }

    public function testInviteRegistrationDoesNotAskProfile(): void
    {
        $client = $this->registrationClient();
        $crawler = $client->request('GET', '/register?invite=some-token');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="registration_form[businessProfile]"]'));
    }

    public function testCompanyNewStoresProfileAndEditDoesNotShowIt(): void
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withEmail('profile-owner@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $this->em()->persist($owner);
        $this->em()->flush();
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/company/new');
        $client->submit($crawler->selectButton('Создать')->form([
            'company[name]' => 'New Universal Co',
            'company[businessProfile]' => 'UNIVERSAL',
        ]));
        self::assertResponseRedirects('/company/');
        $company = $this->companyNamed('New Universal Co');
        self::assertSame(BusinessProfile::UNIVERSAL, $company->getBusinessProfile());

        $crawler = $client->request('GET', sprintf('/company/%s/edit', $company->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="company[businessProfile]"]'));
    }

    public function testAdminAccountCreationStoresProfile(): void
    {
        $client = static::createClient();
        $admin = UserBuilder::aUser()->withEmail('profile-admin@example.test')->withRoles(['ROLE_ADMIN'])->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        $client->loginUser($admin, 'admin');

        $crawler = $client->request('GET', '/admin/users');
        self::assertCount(1, $crawler->filter('input[name="admin_account_create[businessProfile]"][value="MARKETPLACE"][checked]'));
        $client->submit($crawler->selectButton('Создать аккаунт')->form([
            'admin_account_create[email]' => 'admin-universal@example.test',
            'admin_account_create[plainPassword]' => 'secret-password',
            'admin_account_create[companyName]' => 'Admin Universal Co',
            'admin_account_create[businessProfile]' => 'UNIVERSAL',
        ]));

        self::assertResponseRedirects('/admin/users');
        self::assertSame(BusinessProfile::UNIVERSAL, $this->companyNamed('Admin Universal Co')->getBusinessProfile());
    }

    public function testCompanyCreatedWithoutChoiceHasDefaultProfile(): void
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withEmail('default-profile@example.test')->build();
        $this->em()->persist($owner);
        $this->em()->flush();

        $company = $client->getContainer()->get(CompanyOwnerMembershipCreator::class)->createCompany($owner, 'Default Profile Co');

        self::assertSame(BusinessProfile::MARKETPLACE, $company->getBusinessProfile());
    }

    private function registrationClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->getContainer()->set(RegistrationRateLimiter::class, new RegistrationRateLimiter());
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());

        return $client;
    }

    /**
     * @return array<string, string|int>
     */
    private function registrationFields(string $email, string $companyName): array
    {
        return [
            'registration_form[companyName]' => $companyName,
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
            'registration_form[website]' => '',
        ];
    }

    private function companyNamed(string $name): Company
    {
        $this->em()->clear();
        $company = $this->em()->getRepository(Company::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function uniqueClientIp(): string
    {
        return sprintf('2001:db8:%x:%x:%x:%x::2', random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF));
    }
}
