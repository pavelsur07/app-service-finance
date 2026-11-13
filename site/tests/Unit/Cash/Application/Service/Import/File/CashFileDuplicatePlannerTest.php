<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service\Import\File;

use App\Cash\Application\Service\Import\File\CashFileDuplicatePlanner;
use App\Cash\Application\Service\Import\File\CashFileNormalizedRows;
use App\Cash\Application\Service\Import\File\CashFileRowNormalizer;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Infrastructure\Import\File\FileTabularReader;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\TemporaryFileFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashFileDuplicatePlannerTest extends TestCase
{
    /** @return iterable<string, array{list<string>}> */
    public static function orders(): iterable
    {
        yield 'numbered first' => [['B', 'A', 'A', '', '', '']];
        yield 'blank first' => [['', '', '', 'A', 'A', 'B']];
    }

    /** @param list<string> $docs */
    #[DataProvider('orders')]
    public function testPlanPreservesQuotaAllocationAndQueriesOncePerHash(array $docs): void
    {
        $company = new Company('00000000-0000-4000-8000-000000000001', new User('00000000-0000-4000-8000-000000000002'));
        $account = new MoneyAccount('00000000-0000-4000-8000-000000000003', $company, MoneyAccountType::BANK, 'Bank', 'RUB');
        $job = new CashFileImportJob('00000000-0000-4000-8000-000000000004', $company, $account, 'file', 'file.csv', 'hash', ['date' => 'Дата', 'amount' => 'Сумма', 'description' => 'Описание', 'doc_number' => 'Номер']);
        $repository = $this->createMock(CashTransactionRepository::class);
        $repository->expects(self::once())->method('countActiveByCompanyDedupeGroupedByDocNumber')->with($company->getId(), self::isType('string'))->willReturn(['A' => 1, '' => 2, 'OTHER' => 1]);
        $rows = new CashFileNormalizedRows(new FileTabularReader(new LegacyXlsConverter(new TemporaryFileFactory(), '/app')), new CashFileRowNormalizer());
        $planner = new CashFileDuplicatePlanner($rows, $repository);
        $path = tempnam(sys_get_temp_dir(), 'quota-');
        self::assertIsString($path);
        rename($path, $path.'.csv');
        $path .= '.csv';
        $stream = fopen($path, 'w');
        self::assertIsResource($stream);
        fputcsv($stream, ['Дата', 'Сумма', 'Описание', 'Номер'], ';', '"', '');
        foreach ($docs as $doc) {
            fputcsv($stream, ['2026-09-01', '10.00', 'Purpose', $doc], ';', '"', '');
        }
        fclose($stream);
        try {
            $hash = $planner->hash((string) $company->getId(), (string) $account->getId(), new \DateTimeImmutable('2026-08-31', new \DateTimeZone('UTC')), 1000, 'Purpose');
            self::assertSame('59a24be7fe71a787f44e4678be20b565382107f13884571a47387d7cb22d6100', $hash);
            $quota = $planner->plan($path, $job);
            self::assertCount(1, $quota);
            self::assertSame('B' === $docs[0] ? ['B' => 1, 'A' => 2, '' => 1] : ['A' => 2, 'B' => 1, '' => 1], $quota[$hash]);
            self::assertSame($hash, $planner->hash((string) $company->getId(), (string) $account->getId(), new \DateTimeImmutable('2026-08-31', new \DateTimeZone('UTC')), 1000, ' (PURPOSE) '));
        } finally {
            unlink($path);
        }
    }
}
