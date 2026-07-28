<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\Company\Entity\Company;
use App\Mcp\Application\Tool\CompanyFindByNameTool;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CompanyFindByNameToolTest extends IntegrationTestCase
{
    private const MCP_COMPANY_ID = '11111111-1111-1111-1111-000000000700';

    public function testReturnsCompanyIdForExactCaseInsensitiveName(): void
    {
        $company = $this->createCompany(701, 'ООО Ромашка MCP');

        $result = json_decode(
            $this->tool()->call(self::MCP_COMPANY_ID, ['name' => '  ооо ромашка mcp  ']),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertSame(['id' => $company->getId()], $result);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidNameProvider')]
    public function testRejectsInvalidName(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Укажите название компании.');

        $this->tool()->call(self::MCP_COMPANY_ID, $arguments);
    }

    public function testRejectsUnknownName(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Компания с таким названием не найдена.');

        $this->tool()->call(self::MCP_COMPANY_ID, ['name' => 'Несуществующая MCP компания']);
    }

    public function testRejectsPartialName(): void
    {
        $this->createCompany(704, 'Exact MCP Company Name');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Компания с таким названием не найдена.');

        $this->tool()->call(self::MCP_COMPANY_ID, ['name' => 'MCP Company']);
    }

    public function testRejectsAmbiguousName(): void
    {
        $this->createCompany(702, 'Duplicate MCP Company');
        $this->createCompany(703, 'duplicate mcp company');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Найдено несколько компаний с таким названием.');

        $this->tool()->call(self::MCP_COMPANY_ID, ['name' => 'DUPLICATE MCP COMPANY']);
    }

    private function tool(): CompanyFindByNameTool
    {
        return self::getContainer()->get(CompanyFindByNameTool::class);
    }

    private function createCompany(int $index, string $name): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($user)
            ->withName($name)
            ->build();

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['name' => '   ']];
        yield 'not a string' => [['name' => 123]];
    }
}
