<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Exception\InvalidCashImportFileException;
use App\Cash\Service\Import\File\FileTabularReader;
use App\Shared\Service\Storage\LegacyXlsTooLargeException;
use App\Shared\Service\Storage\LegacyXlsTooManyCellsException;
use App\Shared\Service\Storage\LegacyXlsUnreadableException;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryFileFactory;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\Exception\ReaderException;

final readonly class UploadCashFileImportAction
{
    public function __construct(private FileTabularReader $reader, private TemporaryFileFactory $temporaryFiles, private ObjectStorageInterface $storage)
    {
    }

    /** @return array<string, string> */
    public function __invoke(MoneyAccount $account, string $path, string $name, bool $uploadValid): array
    {
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        if (!$uploadValid || !is_file($path) || !is_readable($path)) {
            throw new InvalidCashImportFileException('Не удалось загрузить или прочитать файл. Повторите загрузку.');
        }
        $size = filesize($path);
        if (false === $size || $size <= 0 || $size > 32 * 1024 * 1024 || !in_array($extension, ['csv', 'xls', 'xlsx'], true)) {
            throw new InvalidCashImportFileException('Загрузите непустой CSV, XLS или XLSX размером не более 32 MiB.');
        }
        try {
            $headers = $this->temporaryFiles->withExtensionPath($extension, function (string $temporary) use ($path): array {
                if (!copy($path, $temporary)) {
                    throw new ObjectStorageException('Failed to buffer an uploaded file.');
                }

                return $this->reader->readHeader($temporary);
            });
        } catch (LegacyXlsTooLargeException|LegacyXlsTooManyCellsException $exception) {
            throw new InvalidCashImportFileException($exception->getMessage(), 0, $exception);
        } catch (LegacyXlsUnreadableException|OpenSpoutException|ReaderException $exception) {
            throw new InvalidCashImportFileException('Файл повреждён или его формат не соответствует CSV/XLS/XLSX. Проверьте файл и повторите загрузку.', 0, $exception);
        }
        if ([] === array_filter($headers, static fn (?string $value): bool => null !== $value && '' !== trim($value))) {
            throw new InvalidCashImportFileException('Файл не содержит заголовков колонок.');
        }
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new InvalidCashImportFileException('Не удалось прочитать загруженный файл.');
        }
        $hash = hash('sha256', $contents);
        $this->storage->write(\App\Cash\Service\Import\File\CashFileImportService::storageKey($hash, $extension), $contents);

        return ['company_id' => (string) $account->getCompany()->getId(), 'account_id' => (string) $account->getId(), 'file_name' => $name, 'file_hash' => $hash, 'stored_ext' => $extension];
    }
}
