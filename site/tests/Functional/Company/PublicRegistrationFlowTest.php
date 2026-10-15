<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Shared\Service\RateLimiter\RegistrationRateLimiter;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class PublicRegistrationFlowTest extends WebTestCaseBase
{
    public function testPublicRegistrationCreatesUserCompanyAndOwnerCompanyMember(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(RegistrationRateLimiter::class, new RegistrationRateLimiter());
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());

        $email = 'owner-registration@example.test';
        $companyName = 'Registration LLC';
        $crawler = $client->request('GET', '/register');

        $form = $crawler->selectButton('Создать аккаунт')->form([
            'registration_form[companyName]' => $companyName,
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
            'registration_form[website]' => '',
        ]);
        $client->submit($form);

        self::assertTrue($client->getResponse()->isRedirect());

        $em = $this->em();
        $userRepository = $em->getRepository(User::class);
        $registeredUser = $userRepository->findOneBy(['email' => $email]);

        self::assertSame(1, $userRepository->count([]));
        self::assertNotNull($registeredUser);

        $companyRepository = $em->getRepository(Company::class);
        $company = $companyRepository->findOneBy(['name' => $companyName]);

        self::assertSame(1, $companyRepository->count([]));
        self::assertNotNull($company);
        self::assertSame($registeredUser->getId(), $company->getUser()?->getId());

        $memberRepository = $em->getRepository(CompanyMember::class);
        $member = $memberRepository->findOneByCompanyAndUser($company, $registeredUser);

        self::assertSame(1, $memberRepository->count([]));
        self::assertNotNull($member);
        self::assertSame($company->getId(), $member->getCompany()->getId());
        self::assertSame($registeredUser->getId(), $member->getUser()->getId());
        self::assertSame(CompanyMember::ROLE_OWNER, $member->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());

        /** @var ProjectDirectionRepository $projectRepository */
        $projectRepository = $client->getContainer()->get(ProjectDirectionRepository::class);
        /** @var FinancialResponsibilityCenterRepository $centerRepository */
        $centerRepository = $client->getContainer()->get(FinancialResponsibilityCenterRepository::class);
        /** @var FinancialResponsibilityCenterProjectRepository $pairRepository */
        $pairRepository = $client->getContainer()->get(FinancialResponsibilityCenterProjectRepository::class);

        $project = $projectRepository->findDefaultForCompany($company);
        $center = $centerRepository->findGeneralByCompanyId((string) $company->getId());
        self::assertInstanceOf(ProjectDirection::class, $project);
        self::assertSame(ProjectDirection::CODE_GENERAL, $project->getSystemCode());
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $center);
        self::assertTrue($pairRepository->isAllowed(
            (string) $company->getId(),
            (string) $project->getId(),
            $center->getId(),
        ));

        // Системные статьи ДДС создаёт Cash по CompanyCreatedEvent (этап 7.4 плана Cash).
        /** @var CashflowCategoryRepository $categoryRepository */
        $categoryRepository = $client->getContainer()->get(CashflowCategoryRepository::class);
        $systemCodes = array_values(array_filter(array_map(
            static fn (CashflowCategory $category): ?string => $category->getSystemCode(),
            $categoryRepository->findBy(['company' => $company]),
        )));
        sort($systemCodes);
        self::assertSame(['CF_FIN', 'CF_INV', 'CF_OP', 'CF_TECH', 'CF_TECH_IN', 'CF_TECH_OUT', 'CF_UNALLOC'], $systemCodes);
    }

    /**
     * Регрессия: кривой email маппился в User::setEmail() до валидации и ронял запрос в 500.
     */
    public function testInvalidEmailIsRejectedByFormInsteadOfCrashing(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(RegistrationRateLimiter::class, new RegistrationRateLimiter());

        foreach (['not-an-email', 'ivan@company', ''] as $email) {
            $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());
            $crawler = $client->request('GET', '/register');

            $form = $crawler->selectButton('Создать аккаунт')->form([
                'registration_form[companyName]' => 'Invalid Email LLC',
                'registration_form[email]' => $email,
                'registration_form[plainPassword]' => 'password123',
                'registration_form[agreeTerms]' => 1,
                'registration_form[website]' => '',
            ]);
            $crawler = $client->submit($form);

            self::assertSame(422, $client->getResponse()->getStatusCode(), sprintf('email "%s"', $email));
            // #<id>_error1 — блок ошибки, привязанный именно к полю email (bootstrap_5_layout),
            // а не статический invalid-feedback, который лежит в шаблоне всегда.
            $error = $crawler->filter('#registration_form_email_error1');
            self::assertSame('Введите корректный email', trim($error->text()), sprintf('email "%s"', $email));
        }

        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
    }

    /**
     * Клиентский гейт не должен быть строже серверного предиката: Assert::email() с
     * FILTER_FLAG_EMAIL_UNICODE принимает unicode в локальной части, а HTML5-валидация
     * type="email" — нет. BrowserKit HTML5 не исполняет, поэтому тип поля проверяем явно.
     */
    public function testUnicodeEmailIsAcceptedAndFieldIsNotHtml5Constrained(): void
    {
        $client = static::createClient();
        $client->getContainer()->set(RegistrationRateLimiter::class, new RegistrationRateLimiter());
        $client->setServerParameter('REMOTE_ADDR', $this->uniqueClientIp());

        $crawler = $client->request('GET', '/register');

        self::assertSame(
            'text',
            $crawler->filter('#registration_form_email')->attr('type'),
            'type="email" отрезал бы unicode-адреса, которые сервер принимает',
        );

        $form = $crawler->selectButton('Создать аккаунт')->form([
            'registration_form[companyName]' => 'Unicode Email LLC',
            'registration_form[email]' => 'иван@example.com',
            'registration_form[plainPassword]' => 'password123',
            'registration_form[agreeTerms]' => 1,
            'registration_form[website]' => '',
        ]);
        $client->submit($form);

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'иван@example.com']));
    }

    private function uniqueClientIp(): string
    {
        return sprintf(
            '2001:db8:%x:%x:%x:%x::1',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
        );
    }
}
