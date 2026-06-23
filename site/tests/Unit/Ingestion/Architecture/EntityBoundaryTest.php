<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Guards the module boundary: App\Ingestion entities must not leak into other
 * modules. Consumers go through IngestionFacade and receive DTOs
 * (e.g. FinancialTransactionView) instead of managed Doctrine entities.
 *
 * Replaces a dedicated boundary tool (deptrac/phparkitect) which is not
 * installed; this keeps the rule enforced without adding a dependency.
 */
final class EntityBoundaryTest extends TestCase
{
    /**
     * Documented temporary exception (Variant B, see ARCHITECTURE.md): the P&L
     * dirty-period entity physically stays in App\Ingestion while App\Finance owns
     * the PnlFacade. Until that entity is moved, these references are tolerated.
     *
     * @var list<string>
     */
    private const ALLOWED_ENTITIES = ['PLDirtyPeriod'];

    public function testIngestionEntitiesAreNotReferencedFromOtherModules(): void
    {
        $violations = [];

        foreach ($this->phpFilesOutsideIngestion() as $file) {
            $contents = (string) file_get_contents($file);
            if (!preg_match_all('/App\\\\Ingestion\\\\Entity\\\\(\w+)/', $contents, $matches)) {
                continue;
            }

            foreach (array_unique($matches[1]) as $entity) {
                if (in_array($entity, self::ALLOWED_ENTITIES, true)) {
                    continue;
                }

                $violations[] = sprintf('%s references App\\Ingestion\\Entity\\%s', $this->relative($file), $entity);
            }
        }

        self::assertSame(
            [],
            $violations,
            "Ingestion entities must not cross module boundaries; use IngestionFacade DTOs.\n".implode("\n", $violations),
        );
    }

    public function testFinancialTransactionEntityIsNeverReferencedOutsideIngestion(): void
    {
        $offenders = [];

        foreach ($this->phpFilesOutsideIngestion() as $file) {
            if (str_contains((string) file_get_contents($file), 'App\\Ingestion\\Entity\\FinancialTransaction')) {
                $offenders[] = $this->relative($file);
            }
        }

        self::assertSame([], $offenders, 'FinancialTransaction must only be reachable as FinancialTransactionView outside App\\Ingestion.');
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesOutsideIngestion(): iterable
    {
        $srcDir = \dirname(__DIR__, 4).'/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/src/Ingestion/')) {
                continue;
            }

            yield $path;
        }
    }

    private function relative(string $path): string
    {
        $marker = '/src/';
        $position = strpos($path, $marker);

        return false === $position ? $path : 'src/'.substr($path, $position + strlen($marker));
    }
}
