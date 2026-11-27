<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Import;

use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Application\ImportListingTagsAction;
use App\Marketplace\Exception\InvalidListingTagsFileException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Читает первый лист файла разметки тегов. Колонки ищутся по заголовку первой строки
 * (`listing_id`, `Теги`), поэтому пользователь может переставлять и добавлять свои колонки.
 */
final readonly class ListingTagsFileReader
{
    public const string ID_HEADER = 'listing_id';
    public const string TAGS_HEADER = 'Теги';

    public function __construct(
        private int $maxRows = ImportListingTagsAction::MAX_ROWS,
    ) {
    }

    /**
     * @return list<ListingTagsFileRow>
     */
    public function read(string $path): array
    {
        $reader = new Reader();
        try {
            $reader->open($path);
        } catch (IOException) {
            throw new InvalidListingTagsFileException('Файл не читается как xlsx.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                return $this->readSheet($sheet->getRowIterator());
            }
        } finally {
            $reader->close();
        }

        throw new InvalidListingTagsFileException('В файле нет листов.');
    }

    /**
     * @param iterable<int, \OpenSpout\Common\Entity\Row> $rows
     *
     * @return list<ListingTagsFileRow>
     */
    private function readSheet(iterable $rows): array
    {
        $idColumn = $tagsColumn = null;
        $result = [];

        foreach ($rows as $number => $row) {
            $values = array_map(self::text(...), $row->toArray());

            if (null === $idColumn) {
                $headers = array_map(static fn (string $value): string => mb_strtolower($value), $values);
                $idColumn = array_search(mb_strtolower(self::ID_HEADER), $headers, true);
                $tagsColumn = array_search(mb_strtolower(self::TAGS_HEADER), $headers, true);
                if (false === $idColumn || false === $tagsColumn) {
                    throw new InvalidListingTagsFileException(sprintf('В первой строке нет колонок «%s» и «%s». Скачайте файл кнопкой «Скачать для разметки».', self::ID_HEADER, self::TAGS_HEADER));
                }

                continue;
            }

            $listingId = $values[$idColumn] ?? '';
            $tags = $values[$tagsColumn] ?? '';
            if ('' === $listingId && '' === $tags) {
                continue;
            }

            if (\count($result) >= $this->maxRows) {
                throw new InvalidListingTagsFileException(sprintf('В файле больше %d строк. Разбейте его на части.', $this->maxRows));
            }

            $result[] = new ListingTagsFileRow($number, $listingId, $tags);
        }

        if (null === $idColumn) {
            throw new InvalidListingTagsFileException('Файл пустой.');
        }

        return $result;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            \is_string($value) => trim($value),
            \is_int($value), \is_float($value) => (string) $value,
            default => '',
        };
    }
}
