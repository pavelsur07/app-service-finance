<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Регрессия спам-атаки 01.2026: название компании вводит регистрирующий, адрес может
 * быть чужим — письмо не должно доставлять этот текст.
 */
final class RegistrationEmailTemplateTest extends KernelTestCase
{
    public function testRegistrationEmailDoesNotRenderCompanyName(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $user = UserBuilder::aUser()->withEmail('owner@example.test')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->withName('Спам-текст example.online')->build();

        foreach (['html', 'txt'] as $format) {
            $body = $twig->render(\sprintf('notifications/email/registration_success.%s.twig', $format), [
                'user' => $user,
                'company' => $company,
            ]);

            self::assertStringNotContainsString('Спам-текст', $body, $format);
            self::assertStringContainsString('Регистрация прошла успешно.', $body, $format);
            self::assertStringContainsString('owner@example.test', $body, $format);
        }
    }
}
