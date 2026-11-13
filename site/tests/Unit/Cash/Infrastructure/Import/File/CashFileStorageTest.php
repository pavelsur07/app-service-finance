<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Infrastructure\Import\File;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Infrastructure\Import\File\CashFileStorage;
use App\Cash\Infrastructure\Import\File\FileTabularReader;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryFileFactory;
use App\Shared\Service\Storage\TemporaryLocalFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashFileStorageTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function lifetimes(): iterable
    {
        yield 'complete' => [false, false];
        yield 'early release' => [true, false];
        yield 'exception' => [true, true];
    }

    #[DataProvider('lifetimes')]
    public function testLegacyStorageFallbackAndCleanupAcrossReaderLifetimes(bool $early, bool $throw): void
    {
        $company = new Company('00000000-0000-4000-8000-000000000001', new User('00000000-0000-4000-8000-000000000002'));
        $account = new MoneyAccount('00000000-0000-4000-8000-000000000003', $company, MoneyAccountType::BANK, 'Bank', 'RUB');
        $job = new CashFileImportJob('00000000-0000-4000-8000-000000000004', $company, $account, 'file', 'file.csv', 'hash', [], ['stored_ext' => ' XLSX ']);
        $objectStorage = $this->createMock(ObjectStorageInterface::class);
        $keys = [];
        $objectStorage->expects(self::exactly(3))->method('exists')->willReturnCallback(static function (string $key) use (&$keys): bool {
            $keys[] = $key;

            return 'cash-file-imports/hash' === $key;
        });
        $objectStorage->expects(self::once())->method('readStream')->with('cash-file-imports/hash')->willReturnCallback(static function () {
            $stream = fopen('php://memory', 'w+');
            self::assertIsResource($stream);
            fwrite($stream, "Date,Amount\n2026-09-01,10\n2026-09-02,20\n");
            rewind($stream);

            return $stream;
        });
        $storage = new CashFileStorage($objectStorage, new TemporaryLocalFile($objectStorage), $this->converter());
        $localPath = null;
        try {
            $storage->withJobFile($job, function (string $path) use (&$localPath, $early, $throw): void {
                $localPath = $path;
                self::assertFileExists($path);
                // A legacy key has no extension, so conversion/reader selection must
                // stay with its existing caller. Exercise lifetime using the same bytes.
                $readable = $path.'.csv';
                copy($path, $readable);
                try {
                    $rows = (new FileTabularReader($this->converter()))->readImportRows($readable);
                    if ($early) {
                        $rows->rewind();
                        self::assertSame(2, $rows->current()['rowNumber']);
                    } else {
                        self::assertCount(2, iterator_to_array($rows));
                    }
                    self::assertFileExists($path);
                    unset($rows);
                    if ($throw) {
                        throw new \DomainException('Reader consumer failed');
                    }
                } finally {
                    unlink($readable);
                }
            }, false);
            self::assertFalse($throw);
        } catch (\DomainException $exception) {
            self::assertTrue($throw);
            self::assertSame('Reader consumer failed', $exception->getMessage());
        }
        self::assertSame(['cash-file-imports/hash.xlsx', 'cash-file-imports/hash.csv', 'cash-file-imports/hash'], $keys);
        self::assertNotNull($localPath);
        self::assertFileDoesNotExist($localPath);
    }

    private function converter(): LegacyXlsConverter
    {
        return new LegacyXlsConverter(new TemporaryFileFactory(), '/app');
    }
}
