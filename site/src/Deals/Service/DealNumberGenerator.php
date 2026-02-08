<?php

namespace App\Deals\Service;

use App\Company\Entity\Company;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

class DealNumberGenerator
{
    private const FORMAT = 'DEAL-%s-%06d';
    private const MAX_ATTEMPTS = 1000;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function generate(Company $company): string
    {
        $companyId = $company->getId();
        Assert::notNull($companyId);

        $year = (new \DateTimeImmutable())->format('Y');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $sequence = $this->nextSequenceNumber($companyId);
            $number = sprintf(self::FORMAT, $year, $sequence);

            if (!$this->numberExists($companyId, $number)) {
                return $number;
            }
        }

        throw new \RuntimeException('Unable to generate unique deal number after multiple attempts.');
    }

    private function nextSequenceNumber(string $companyId): int
    {
        $sql = <<<'SQL'
            INSERT INTO deals_deal_sequence (company_id, last_number)
            VALUES (:company_id, 1)
            ON CONFLICT (company_id)
                DO UPDATE SET last_number = deals_deal_sequence.last_number + 1
            RETURNING last_number
            SQL;

        $value = $this->connection->fetchOne($sql, ['company_id' => $companyId]);

        return (int) $value;
    }

    private function numberExists(string $companyId, string $number): bool
    {
        $sql = 'SELECT 1 FROM deals WHERE company_id = :company_id AND number = :number LIMIT 1';

        return (bool) $this->connection->fetchOne($sql, [
            'company_id' => $companyId,
            'number' => $number,
        ]);
    }
}
