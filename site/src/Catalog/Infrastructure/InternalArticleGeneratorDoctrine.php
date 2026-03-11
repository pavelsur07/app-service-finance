<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\InternalArticleGenerator;
use Doctrine\DBAL\Connection;

/**
 * Генерирует артикул формата PRD-{YYYY}-{NNNNNN}.
 * Использует таблицу product_import_sequences.
 * ON CONFLICT + UPDATE гарантирует атомарность без гонок при параллельных импортах.
 */
final class InternalArticleGeneratorDoctrine implements InternalArticleGenerator
{
    private const PREFIX     = 'PRD';
    private const PAD_LENGTH = 6;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function generate(string $companyId): string
    {
        $year = (int) (new \DateTimeImmutable())->format('Y');

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'INSERT INTO product_import_sequences (company_id, year, last_seq)
                 VALUES (:companyId, :year, 0)
                 ON CONFLICT (company_id, year) DO NOTHING',
                ['companyId' => $companyId, 'year' => $year],
            );

            $this->connection->executeStatement(
                'UPDATE product_import_sequences
                 SET last_seq = last_seq + 1
                 WHERE company_id = :companyId AND year = :year',
                ['companyId' => $companyId, 'year' => $year],
            );

            $seq = (int) $this->connection->fetchOne(
                'SELECT last_seq FROM product_import_sequences
                 WHERE company_id = :companyId AND year = :year',
                ['companyId' => $companyId, 'year' => $year],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw new \RuntimeException('Failed to generate internal article: '.$e->getMessage(), 0, $e);
        }

        return sprintf(
            '%s-%d-%s',
            self::PREFIX,
            $year,
            str_pad((string) $seq, self::PAD_LENGTH, '0', STR_PAD_LEFT),
        );
    }
}
