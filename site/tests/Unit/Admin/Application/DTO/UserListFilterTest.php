<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin\Application\DTO;

use App\Admin\Application\DTO\UserListFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserListFilterTest extends TestCase
{
    private const COMPANY_ID = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    /**
     * @return iterable<string, array{mixed, mixed, ?string, bool, bool, bool}>
     */
    public static function queries(): iterable
    {
        yield 'нет параметров' => [null, null, null, false, false, false];
        yield 'пустой кабинет' => ['', null, null, false, false, false];
        yield 'без кабинета' => ['none', null, null, true, false, true];
        yield 'валидный UUID' => [self::COMPANY_ID, null, self::COMPANY_ID, false, false, true];
        yield 'мусор вместо UUID' => ['not-a-uuid', null, null, false, false, false];
        yield 'массив вместо строки' => [[self::COMPANY_ID], null, null, false, false, false];
        yield 'только владельцы' => [null, '1', null, false, true, true];
        yield 'owners не 1' => [null, 'yes', null, false, false, false];
        yield 'owners числом' => [null, 1, null, false, false, false];
        yield 'кабинет и владельцы' => [self::COMPANY_ID, '1', self::COMPANY_ID, false, true, true];
    }

    #[DataProvider('queries')]
    public function testFromQuery(
        mixed $company,
        mixed $owners,
        ?string $expectedCompanyId,
        bool $expectedWithoutCompany,
        bool $expectedOwnersOnly,
        bool $expectedActive,
    ): void {
        $filter = UserListFilter::fromQuery($company, $owners);

        self::assertSame($expectedCompanyId, $filter->companyId);
        self::assertSame($expectedWithoutCompany, $filter->withoutCompany);
        self::assertSame($expectedOwnersOnly, $filter->ownersOnly);
        self::assertSame($expectedActive, $filter->isActive());
    }
}
