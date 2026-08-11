<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Security;

use App\Company\Security\SystemCompanyRoles;
use PHPUnit\Framework\TestCase;

/**
 * Защита от дрейфа между SystemCompanyRoles::definitions() и SQL-сидером миграции.
 * Миграция Version20260811120000 дублирует значения definitions; этот тест проверяет идентичность.
 */
final class CompanyRoleMigrationParityTest extends TestCase
{
    public function testMigrationSeedMatchesSystemCompanyRolesDefinitions(): void
    {
        $migrationPath = \dirname(__DIR__, 4).'/migrations/Version20260811120000.php';
        self::assertFileExists($migrationPath);

        $migrationSource = file_get_contents($migrationPath);
        self::assertIsString($migrationSource);

        $rows = $this->extractSeedRows($migrationSource);
        self::assertCount(5, $rows, 'Ожидается 5 системных шаблонов в миграции.');

        $expected = SystemCompanyRoles::definitions();
        self::assertCount(5, $expected);

        foreach ($expected as $id => $definition) {
            self::assertArrayHasKey($id, $rows, sprintf('Шаблон %s отсутствует в миграции.', $id));

            $row = $rows[$id];
            self::assertSame($definition['name'], $row['name'], sprintf('Имя шаблона %s не совпадает.', $id));
            self::assertSame($definition['permissions'], $row['permissions'], sprintf('Права шаблона %s не совпадают.', $id));
        }
    }

    /**
     * @return array<string, array{name: string, permissions: array<string, string>}>
     */
    private function extractSeedRows(string $source): array
    {
        preg_match('/INSERT INTO company_role[^V]*VALUES\s+(.+?)ON CONFLICT/s', $source, $matches);
        self::assertSame(2, \count($matches), 'Не удалось найти INSERT системных шаблонов в миграции.');

        $valuesBlock = $matches[1];

        // Каждая строка: ('uuid', NULL, 'Name', '{"module":"level",...}', CURRENT_TIMESTAMP)
        preg_match_all(
            "/\\('([0-9a-f-]+)',\\s*NULL,\\s*'([^']+)',\\s*'([^']+)'/",
            $valuesBlock,
            $rows,
            \PREG_SET_ORDER,
        );

        $result = [];
        foreach ($rows as $row) {
            $permissions = json_decode($row[3], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($permissions);

            $result[$row[1]] = [
                'name' => $row[2],
                'permissions' => $permissions,
            ];
        }

        return $result;
    }
}
