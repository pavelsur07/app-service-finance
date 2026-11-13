<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryLocalFile;

final readonly class CashFileStorage
{
    public function __construct(private ObjectStorageInterface $objectStorage, private TemporaryLocalFile $temporaryLocalFile, private LegacyXlsConverter $xlsConverter)
    {
    }

    public static function storageKey(string $fileHash, string $extension = ''): string
    {
        return 'cash-file-imports/'.$fileHash.('' !== $extension ? '.'.$extension : '');
    }

    public function withReadablePath(string $path, \Closure $operation): mixed
    {
        return $this->xlsConverter->withReadablePath($path, $operation);
    }

    public function withJobFile(CashFileImportJob $job, \Closure $operation, bool $readable = true): mixed
    {
        return $this->temporaryLocalFile->with($this->resolveStorageKey($job),
            fn (string $path) => $readable ? $this->withReadablePath($path, $operation) : $operation($path));
    }

    private function resolveStorageKey(CashFileImportJob $job): string
    {
        $fileHash = $job->getFileHash();

        $extensions = [];
        $options = $job->getOptions();
        $storedExtension = $options['stored_ext'] ?? $options['extension'] ?? null;
        if (is_string($storedExtension)) {
            $storedExtension = strtolower(trim($storedExtension));
            if ('' !== $storedExtension) {
                $extensions[] = $storedExtension;
            }
        }

        $fileExtension = pathinfo($job->getFilename(), \PATHINFO_EXTENSION);
        if ('' !== $fileExtension) {
            $extensions[] = strtolower($fileExtension);
        }

        $candidates = [];
        foreach (array_values(array_unique($extensions)) as $extension) {
            $candidates[] = self::storageKey($fileHash, $extension);
        }
        $candidates[] = self::storageKey($fileHash);

        foreach ($candidates as $candidate) {
            if ($this->objectStorage->exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf('Import file not found for hash %s', $fileHash));
    }
}
