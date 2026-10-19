<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\DTO\ListingTagAssignResult;
use App\Marketplace\Application\DTO\ListingTagPayload;
use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\ListingFilterTooBroadException;
use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Infrastructure\Query\MarketplaceListingsQuery;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Назначает тег набору листингов: либо существующий по tagId,
 * либо создаваемый на лету по name (чип «Создать» в реестре).
 *
 * Набор — явный список listingIds или фильтр реестра: тогда листинги находит
 * тот же запрос, что строит список, и тег получают ровно показанные строки.
 */
final class AssignListingTagAction
{
    public const DEFAULT_MAX_FILTER_MATCH = 5000;
    private const ASSIGN_CHUNK = 500;

    public function __construct(
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly ListingTagAssignmentRepository $assignments,
        private readonly MarketplaceListingsQuery $listingsQuery,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly int $maxFilterMatch = self::DEFAULT_MAX_FILTER_MATCH,
    ) {
    }

    public function __invoke(string $companyId, ListingTagPayload $payload): ListingTagAssignResult
    {
        // Выборка — до транзакции и до создания тега: слишком широкий фильтр
        // отказывает, не открыв транзакцию и не оставив тег-сироту.
        if (null !== $payload->filter) {
            $listingIds = $this->listingsQuery->matchingIds($companyId, $payload->filter, $this->maxFilterMatch + 1);
            if (count($listingIds) > $this->maxFilterMatch) {
                throw new ListingFilterTooBroadException($this->maxFilterMatch);
            }
            $matched = count($listingIds);
        } else {
            $listingIds = $payload->listingIds;
            $matched = $this->assignments->countCompanyListings($companyId, $listingIds);
        }

        // Транзакция на весь write-path: создание тега + вставка назначений (оба DBAL)
        // атомарны — сбой между ними не оставляет тег-сироту без назначений.
        $result = $this->em->wrapInTransaction(function () use ($companyId, $payload, $listingIds, $matched): ListingTagAssignResult {
            $tag = null !== $payload->tagId
                ? $this->requireTag($companyId, $payload->tagId)
                : $this->findOrCreate($companyId, (string) $payload->tagName);

            $assigned = 0;
            foreach (array_chunk($listingIds, self::ASSIGN_CHUNK) as $chunk) {
                $assigned += $this->assignments->assign($companyId, $chunk, $tag->getId());
            }

            return new ListingTagAssignResult($tag->getId(), $tag->getName(), $assigned, $matched);
        });

        if (null !== $payload->filter) {
            // Текст поиска не пишем: в лог — только его длина.
            $this->logger->info('Listing tag assigned by filter', [
                'companyId' => $companyId,
                'tagId' => $result->tagId,
                'matched' => $result->matched,
                'assigned' => $result->assigned,
                'searchLength' => \mb_strlen($payload->filter->search ?? ''),
            ]);
        }

        return $result;
    }

    private function requireTag(string $companyId, string $tagId): MarketplaceListingTag
    {
        $tag = $this->tagRepository->findById($companyId, $tagId);

        if (null === $tag) {
            throw new ListingTagNotFoundException($tagId);
        }

        return $tag;
    }

    private function findOrCreate(string $companyId, string $name): MarketplaceListingTag
    {
        $slug = MarketplaceListingTag::slugify($name);
        $existing = $this->tagRepository->findBySlug($companyId, $slug);

        if (null !== $existing) {
            return $existing;
        }

        // Не persist + flush: одноимённый тег, созданный параллельным запросом между
        // findBySlug() и вставкой, уронил бы flush на uniq_listing_tag_company_slug (500).
        // ON CONFLICT DO NOTHING оставляет тот тег, и листинги получают его.
        $this->tagRepository->insertIfAbsent(new MarketplaceListingTag(Uuid::uuid7()->toString(), $companyId, $name));

        return $this->tagRepository->findBySlug($companyId, $slug)
            ?? throw new ListingTagNotFoundException($slug);
    }
}
