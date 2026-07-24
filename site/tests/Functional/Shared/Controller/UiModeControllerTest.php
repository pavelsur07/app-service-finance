<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Controller;

use App\Shared\Service\UiModeResolver;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class UiModeControllerTest extends WebTestCaseBase
{
    public function testAdminCanSwitchModeAndKeepSameOriginLocation(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $admin = UserBuilder::aUser()
            ->withEmail('ui-mode-admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        $client->loginUser($admin);
        $client->setServerParameter('HTTPS', 'on');
        $crawler = $client->request('GET', '/company/');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Новый')->form();
        $client->setServerParameter('HTTP_REFERER', 'https://localhost/finance/cashflow?period=month');

        $client->submit($form);

        self::assertResponseRedirects('/finance/cashflow?period=month', Response::HTTP_SEE_OTHER);
        $cookie = $this->uiModeCookie($client->getResponse()->headers->getCookies());
        self::assertSame(UiModeResolver::APP, $cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertGreaterThan(time() + 300 * 24 * 60 * 60, $cookie->getExpiresTime());
    }

    public function testCrossOriginRefererFallsBackToDashboard(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request(
            'POST',
            '/settings/ui-mode',
            [
                '_token' => $this->csrfToken($client, 'ui_mode_switch'),
                'mode' => UiModeResolver::LEGACY,
            ],
            [],
            ['HTTP_REFERER' => 'https://example.org/steal'],
        );

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        self::assertFalse($this->uiModeCookie($client->getResponse()->headers->getCookies())->isSecure());
    }

    public function testSchemeRelativeRefererPathFallsBackToDashboard(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request(
            'POST',
            '/settings/ui-mode',
            [
                '_token' => $this->csrfToken($client, 'ui_mode_switch'),
                'mode' => UiModeResolver::APP,
            ],
            [],
            ['HTTP_REFERER' => 'http://localhost//example.org/steal'],
        );

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    public function testInvalidModeIsRejectedWithoutCookie(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('POST', '/settings/ui-mode', [
            '_token' => $this->csrfToken($client, 'ui_mode_switch'),
            'mode' => 'unknown',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertNull($this->findUiModeCookie($client->getResponse()->headers->getCookies()));
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $client->request('POST', '/settings/ui-mode', [
            '_token' => 'invalid',
            'mode' => UiModeResolver::APP,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLegacyCookieSelectsAppButtonForAdmin(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $client->getCookieJar()->set(new BrowserCookie('ui_theme', 'vf'));

        $crawler = $client->request('GET', '/company/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Новый',
            trim($crawler->filter('.ui-mode-switch button[aria-pressed="true"]')->text()),
        );
    }

    public function testNonAdminCannotSwitchMode(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $user = UserBuilder::aUser()
            ->withEmail('ui-mode-user@example.test')
            ->build();
        $this->em()->persist($user);
        $this->em()->flush();
        $client->loginUser($user);

        $client->request('POST', '/settings/ui-mode', [
            '_token' => $this->csrfToken($client, 'ui_mode_switch'),
            'mode' => UiModeResolver::APP,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @param list<Cookie> $cookies
     */
    private function uiModeCookie(array $cookies): Cookie
    {
        $cookie = $this->findUiModeCookie($cookies);
        self::assertInstanceOf(Cookie::class, $cookie, 'UI mode cookie was not set.');

        return $cookie;
    }

    /**
     * @param list<Cookie> $cookies
     */
    private function findUiModeCookie(array $cookies): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if (UiModeResolver::COOKIE_NAME === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $this->resetDb();
        $admin = UserBuilder::aUser()
            ->withEmail('ui-mode-admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();
        $this->em()->persist($admin);
        $this->em()->flush();
        $client->loginUser($admin);
    }
}
