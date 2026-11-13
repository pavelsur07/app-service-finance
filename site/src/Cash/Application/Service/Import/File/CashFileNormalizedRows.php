<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\Import\File;

use App\Cash\Infrastructure\Import\File\FileTabularReader;

/**
 * @phpstan-import-type InputRow from CashFileDescriptionPlan
 * @phpstan-import-type NormalizedRow from CashFileImportService
 */
final readonly class CashFileNormalizedRows
{
    public function __construct(private FileTabularReader $reader, private CashFileRowNormalizer $normalizer)
    {
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<int, InputRow>|null $allowedRows
     *
     * @return \Generator<int, array{rowNumber: int, row: array<string, ?string>, normalized: NormalizedRow}>
     */
    public function iterate(string $path, array $mapping, string $currency, ?array $allowedRows = null): \Generator
    {
        foreach ($this->reader->readImportRows($path) as $item) {
            if (null !== $allowedRows && !isset($allowedRows[$item['rowNumber']])) {
                continue;
            }
            yield $item + ['normalized' => $this->normalizer->normalize($item['row'], $mapping, $currency)];
        }
    }
}
