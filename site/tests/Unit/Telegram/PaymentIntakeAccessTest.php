<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Telegram\Application\PaymentIntakeAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentIntakeAccessTest extends TestCase
{
    private const COMPANY = '11111111-1111-1111-1111-abcd0000ee01';

    public function testMembershipIsIndependentOfGlobalSwitch(): void
    {
        $access = new PaymentIntakeAccess(false, self::COMPANY);
        self::assertTrue($access->isPilotCompany(self::COMPANY));
        self::assertFalse($access->isAllowed(self::COMPANY));
    }

    public function testValidCsvNormalizesUuidCaseAndWhitespace(): void
    {
        $access = new PaymentIntakeAccess(true, ' '.strtoupper(self::COMPANY).', 11111111-1111-1111-1111-abcd0000ee02 ');
        self::assertTrue($access->isAllowed(self::COMPANY));
        self::assertTrue($access->isAllowed('11111111-1111-1111-1111-abcd0000ee02'));
        self::assertFalse($access->isPilotCompany('11111111-1111-1111-1111-abcd0000ee03'));
    }

    #[DataProvider('invalidScopeProvider')]
    public function testInvalidWholeListFailsClosed(string $scope): void
    {
        $access = new PaymentIntakeAccess(true, $scope);
        self::assertFalse($access->isPilotCompany(self::COMPANY));
        self::assertFalse($access->isAllowed(self::COMPANY));
    }

    /** @return array<string, array{string}> */
    public static function invalidScopeProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['  '],
            'wildcard' => ['*'],
            'wildcard after uuid' => [self::COMPANY.',*'],
            'malformed after uuid' => [self::COMPANY.',invalid'],
            'trailing separator' => [self::COMPANY.','],
            'leading separator' => [','.self::COMPANY],
            'empty middle item' => [self::COMPANY.',,'.self::COMPANY],
            'compact uuid' => [str_replace('-', '', self::COMPANY)],
            'urn uuid' => ['urn:uuid:'.self::COMPANY],
        ];
    }
}
