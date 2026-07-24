<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Service;

use App\Shared\Service\UiModeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class UiModeResolverTest extends TestCase
{
    /**
     * @param array<string, mixed> $cookies
     */
    #[DataProvider('modeCases')]
    public function testResolvesMode(array $cookies, string $expected): void
    {
        $resolver = $this->resolver();

        self::assertSame($expected, $resolver->resolve(new Request(cookies: $cookies)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function modeCases(): iterable
    {
        yield 'default' => [[], UiModeResolver::LEGACY];
        yield 'new legacy value' => [['ui_mode' => 'legacy'], UiModeResolver::LEGACY];
        yield 'new app value' => [['ui_mode' => 'app'], UiModeResolver::APP];
        yield 'invalid new value is safe' => [['ui_mode' => 'unknown'], UiModeResolver::LEGACY];
        yield 'non-scalar new value is safe' => [['ui_mode' => ['nested' => 'app']], UiModeResolver::LEGACY];
        yield 'old vf value remains compatible' => [['ui_theme' => 'vf'], UiModeResolver::APP];
        yield 'old tabler value remains legacy' => [['ui_theme' => 'tabler'], UiModeResolver::LEGACY];
        yield 'new value wins over old cookie' => [
            ['ui_mode' => 'legacy', 'ui_theme' => 'vf'],
            UiModeResolver::LEGACY,
        ];
    }

    public function testCurrentDefaultsToLegacyOutsideRequest(): void
    {
        $resolver = $this->resolver();

        self::assertSame(UiModeResolver::LEGACY, $resolver->current());
    }

    public function testNonAdminCannotEnableAppModeWithForgedCookie(): void
    {
        $resolver = $this->resolver(false);

        self::assertSame(
            UiModeResolver::LEGACY,
            $resolver->resolve(new Request(cookies: ['ui_mode' => 'app'])),
        );
    }

    private function resolver(bool $isAdmin = true): UiModeResolver
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->with('ROLE_ADMIN')->willReturn($isAdmin);

        return new UiModeResolver(new RequestStack(), $authorizationChecker);
    }
}
