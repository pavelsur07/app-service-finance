<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Application\DTO\ListingTagsImportResult;
use App\Marketplace\DTO\ListingTagDTO;
use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\InvalidListingTagsFileException;
use App\Marketplace\Exception\ListingTagsImportOutdatedException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Разметка тегов листингов из файла: без `$applyFingerprint` — предпросмотр без записи,
 * с ним — применение ровно того плана, что был показан.
 *
 * Правила ячейки «Теги»: пусто — листинг не трогается; `-` — снять все теги;
 * иначе теги через `;`. Режим «добавить» дописывает недостающие, «заменить» ещё и снимает
 * теги, которых нет в ячейке. Листинги, которых нет в файле, не трогаются никогда.
 * Строки с ошибкой (не наш листинг, длинный тег, повтор) пропускаются и показываются.
 */
final class ImportListingTagsAction
{
    public const int MAX_ROWS = 5000;
    public const string CLEAR_MARKER = '-';
    private const int CHUNK = 500;

    public function __construct(
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly ListingTagAssignmentRepository $assignments,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<ListingTagsFileRow> $rows
     */
    public function __invoke(string $companyId, array $rows, bool $replace, ?string $applyFingerprint = null): ListingTagsImportResult
    {
        if (\count($rows) > self::MAX_ROWS) {
            throw new InvalidListingTagsFileException(sprintf('Больше %d строк. Разбейте файл на части.', self::MAX_ROWS));
        }

        if (null === $applyFingerprint) {
            return $this->plan($companyId, $rows, $replace);
        }

        // Только DBAL-запись, поэтому транзакция соединения: отказ по отпечатку откатывает её,
        // не закрывая EntityManager. План пересчитывается внутри — окно между сверкой и записью
        // минимально; параллельный импорт безопасен: assign — ON CONFLICT, detach — идемпотентен.
        return $this->connection->transactional(function () use ($companyId, $rows, $replace, $applyFingerprint): ListingTagsImportResult {
            $plan = $this->plan($companyId, $rows, $replace);
            if (!hash_equals($applyFingerprint, $plan->fingerprint)) {
                throw new ListingTagsImportOutdatedException();
            }

            $this->apply($companyId, $plan);

            return new ListingTagsImportResult($plan->changes, $plan->newTags, $plan->errors, $plan->fingerprint, true);
        });
    }

    /**
     * @param list<ListingTagsFileRow> $rows
     */
    private function plan(string $companyId, array $rows, bool $replace): ListingTagsImportResult
    {
        $errors = [];
        /** @var array<string, array{row: int, clear: bool, tags: array<string, string>}> $desired slug → имя */
        $desired = [];
        $seen = [];

        foreach ($rows as $row) {
            $listingId = mb_strtolower(trim($row->listingId));
            $cell = trim($row->tags);
            if ('' === $listingId && '' === $cell) {
                continue;
            }
            if (!Uuid::isValid($listingId)) {
                $errors[] = ['row' => $row->rowNumber, 'message' => 'Не распознан listing_id.'];
                continue;
            }
            if (isset($seen[$listingId])) {
                $errors[] = ['row' => $row->rowNumber, 'message' => sprintf('Листинг уже есть в строке %d.', $seen[$listingId])];
                continue;
            }
            $seen[$listingId] = $row->rowNumber;

            if ('' === $cell) {
                continue;
            }
            if (self::CLEAR_MARKER === $cell) {
                $desired[$listingId] = ['row' => $row->rowNumber, 'clear' => true, 'tags' => []];
                continue;
            }

            $tags = [];
            foreach (explode(';', $cell) as $name) {
                $name = trim($name);
                if ('' === $name) {
                    continue;
                }
                if (mb_strlen($name) > MarketplaceListingTag::NAME_MAX_LENGTH) {
                    $errors[] = ['row' => $row->rowNumber, 'message' => sprintf('Тег длиннее %d символов: «%s…».', MarketplaceListingTag::NAME_MAX_LENGTH, mb_substr($name, 0, 20))];
                    continue 2;
                }
                $tags[MarketplaceListingTag::slugify($name)] ??= $name;
            }
            if ([] !== $tags) {
                $desired[$listingId] = ['row' => $row->rowNumber, 'clear' => false, 'tags' => $tags];
            }
        }

        $ids = array_keys($desired);
        $names = [];
        $current = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $names += $this->assignments->companyListingNames($companyId, $chunk);
            $current += $this->assignments->tagsForListings($companyId, $chunk);
        }

        $companyTags = [];
        foreach ($this->tagRepository->listForCompany($companyId) as $tag) {
            $companyTags[MarketplaceListingTag::slugify($tag->name)] = $tag->name;
        }

        $changes = [];
        $newTags = [];
        foreach ($desired as $listingId => $wanted) {
            if (!isset($names[$listingId])) {
                $errors[] = ['row' => $wanted['row'], 'message' => 'Листинг не найден в компании.'];
                continue;
            }

            $have = [];
            foreach ($current[$listingId] ?? [] as $tag) {
                $have[MarketplaceListingTag::slugify($tag->name)] = $tag;
            }

            $add = array_diff_key($wanted['tags'], $have);
            $remove = $wanted['clear'] || $replace ? array_diff_key($have, $wanted['tags']) : [];
            if ([] === $add && [] === $remove) {
                continue;
            }

            foreach ($add as $slug => $name) {
                if (!isset($companyTags[$slug])) {
                    $newTags[$slug] ??= $name;
                }
            }
            $changes[] = [
                'row' => $wanted['row'],
                'listingId' => $listingId,
                'name' => $names[$listingId],
                // Подпись существующего тега — как в справочнике, а не как в файле.
                'add' => array_map(static fn (string $slug): string => $companyTags[$slug] ?? $add[$slug], array_keys($add)),
                'remove' => array_values(array_map(static fn (ListingTagDTO $tag): string => $tag->name, $remove)),
            ];
        }

        usort($errors, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return new ListingTagsImportResult($changes, array_values($newTags), $errors, $this->fingerprint($replace, $changes, $newTags), false);
    }

    private function apply(string $companyId, ListingTagsImportResult $plan): void
    {
        foreach ($plan->newTags as $name) {
            $this->tagRepository->insertIfAbsent(new MarketplaceListingTag(Uuid::uuid7()->toString(), $companyId, $name));
        }

        $tagIds = [];
        foreach ($this->tagRepository->listForCompany($companyId) as $tag) {
            $tagIds[MarketplaceListingTag::slugify($tag->name)] = $tag->id;
        }

        $toAdd = $toRemove = [];
        foreach ($plan->changes as $change) {
            foreach ($change['add'] as $name) {
                $toAdd[$tagIds[MarketplaceListingTag::slugify($name)]][] = $change['listingId'];
            }
            foreach ($change['remove'] as $name) {
                $toRemove[$tagIds[MarketplaceListingTag::slugify($name)]][] = $change['listingId'];
            }
        }

        foreach ($toAdd as $tagId => $listingIds) {
            foreach (array_chunk($listingIds, self::CHUNK) as $chunk) {
                $this->assignments->assign($companyId, $chunk, (string) $tagId);
            }
        }
        foreach ($toRemove as $tagId => $listingIds) {
            foreach (array_chunk($listingIds, self::CHUNK) as $chunk) {
                $this->assignments->detach($companyId, $chunk, (string) $tagId);
            }
        }
    }

    /**
     * @param list<array{row: int, listingId: string, name: string, add: list<string>, remove: list<string>}> $changes
     * @param array<string, string> $newTags
     */
    private function fingerprint(bool $replace, array $changes, array $newTags): string
    {
        $slugs = static fn (array $names): array => array_map(MarketplaceListingTag::slugify(...), $names);
        $lines = array_map(
            static fn (array $change): string => $change['listingId'].'+'.implode(',', $slugs($change['add'])).'-'.implode(',', $slugs($change['remove'])),
            $changes,
        );
        sort($lines);
        $tags = array_keys($newTags);
        sort($tags);

        return hash('sha256', json_encode([$replace, $lines, $tags], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }
}
