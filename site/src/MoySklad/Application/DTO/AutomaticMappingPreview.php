<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\Marketplace\DTO\ListingMetaDTO;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class AutomaticMappingPreview
{
    /** @param list<AutomaticMappingPreviewItem> $items
     * @param array<string,int> $counts
     */
    public function __construct(
        public string $id, public string $companyId, public string $actorUserId, public string $connectionId,
        public string $marketplace, public int $createdAt, public int $expiresAt, public string $ruleVersion,
        public string $q, public int $page, public ?string $sizeCharacteristicId, public array $items, public array $counts,
    ) {
        foreach ([$id, $companyId, $actorUserId, $connectionId] as $uuid) {
            Assert::uuid($uuid);
        }
        Assert::inArray($marketplace, ['ozon', 'wildberries']);
        Assert::maxCount($items, 25);
        Assert::same($ruleVersion, AutomaticMappingGuard::RULE_VERSION);
        Assert::greaterThan($expiresAt, $createdAt);
        Assert::lessThanEq($expiresAt - $createdAt, 900);
    }

    public static function fromPage(string $companyId, string $actorUserId, string $connectionId, string $marketplace, AutomaticMappingPreviewPage $page, \DateTimeImmutable $now): self
    {
        $policy = new AutomaticListingMappingPolicy();
        $items = [];
        foreach ($page->listingsPage->items as $source) {
            $items[] = new AutomaticMappingPreviewItem($source, $policy->sourceFingerprint($source, $page->barcodes[$source->id] ?? [], $page->settings), Uuid::uuid7()->toString(), $page->decisions[$source->id]);
        }

        return new self(Uuid::uuid7()->toString(), $companyId, $actorUserId, $connectionId, $marketplace,
            $now->getTimestamp(), $now->getTimestamp() + 900, AutomaticMappingGuard::RULE_VERSION, $page->q,
            $page->listingsPage->page, $page->sizeCharacteristicId, $items, $page->counts);
    }

    /** Scalar arrays only: no PHP object serialization in Redis.
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        /** @var array<string,mixed> $data */
        $data = json_decode(json_encode($this, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ($data['items'] as $item) {
            $decision = AutomaticMappingDecision::fromArray($item['decision']);
            $items[] = new AutomaticMappingPreviewItem(new ListingMetaDTO(...$item['source']), $item['sourceFingerprint'], $item['requestId'], $decision);
        }

        return new self($data['id'], $data['companyId'], $data['actorUserId'], $data['connectionId'], $data['marketplace'],
            $data['createdAt'], $data['expiresAt'], $data['ruleVersion'], $data['q'], $data['page'], $data['sizeCharacteristicId'], $items, $data['counts']);
    }
}
