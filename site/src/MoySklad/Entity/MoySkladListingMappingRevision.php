<?php

declare(strict_types=1);

namespace App\MoySklad\Entity;

use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MoySkladListingMappingRepository::class)]
#[ORM\Table(name: 'moysklad_listing_mapping_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_ms_mapping_revision', columns: ['company_id', 'connection_id', 'listing_id', 'revision'])]
#[ORM\UniqueConstraint(name: 'uniq_ms_mapping_request', columns: ['company_id', 'request_id'])]
#[ORM\Index(name: 'idx_ms_mapping_product', columns: ['company_id', 'connection_id', 'product_external_id'], options: ['where' => '(product_external_id IS NOT NULL)'])]
#[ORM\Index(name: 'idx_ms_mapping_variant', columns: ['company_id', 'connection_id', 'variant_external_id'], options: ['where' => '(variant_external_id IS NOT NULL)'])]
class MoySkladListingMappingRevision
{
    private const UUID_PATTERN = '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD';
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;
    #[ORM\Column(type: Types::GUID)]
    private string $companyId;
    #[ORM\Column(type: Types::GUID)]
    private string $connectionId;
    #[ORM\Column(type: Types::GUID)]
    private string $listingId;
    #[ORM\Column(type: Types::INTEGER)]
    private int $revision;
    #[ORM\Column(type: Types::INTEGER)]
    private int $expectedRevision;
    #[ORM\Column(type: Types::STRING, length: 8, enumType: ListingMappingOperation::class)]
    private ListingMappingOperation $operation;
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $productExternalId = null;
    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $variantExternalId = null;
    #[ORM\Column(type: Types::GUID)]
    private string $requestId;
    #[ORM\Column(type: Types::GUID)]
    private string $actorUserId;
    #[ORM\Column(type: 'datetime_immutable_utc_ms', precision: 3)]
    private \DateTimeImmutable $createdAt;
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    private array $listingSnapshot;
    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private ?array $targetSnapshot;

    /**
     * @param array<string, mixed> $listingSnapshot
     * @param array<string, mixed>|null $targetSnapshot
     */
    public function __construct(
        string $id, string $companyId, string $connectionId, string $listingId,
        int $revision, ListingMappingOperation $operation, ?string $targetType, ?string $targetExternalId,
        string $requestId, string $actorUserId, int $expectedRevision, \DateTimeImmutable $createdAt,
        array $listingSnapshot, ?array $targetSnapshot,
    ) {
        foreach ([$id, $companyId, $connectionId, $listingId, $requestId, $actorUserId] as $uuid) {
            Assert::regex($uuid, self::UUID_PATTERN);
        }
        Assert::greaterThan($revision, 0);
        Assert::greaterThanEq($expectedRevision, 0);
        Assert::eq($revision, $expectedRevision + 1);
        Assert::eq($listingSnapshot['id'] ?? null, strtolower($listingId));
        if (ListingMappingOperation::SET === $operation) {
            Assert::inArray($targetType, ['product', 'variant']);
            Assert::string($targetExternalId);
            Assert::regex($targetExternalId, self::UUID_PATTERN);
            Assert::notNull($targetSnapshot);
            Assert::eq($targetSnapshot['type'] ?? null, $targetType);
            Assert::eq($targetSnapshot['externalId'] ?? null, strtolower($targetExternalId));
            if ('product' === $targetType) {
                $this->productExternalId = strtolower($targetExternalId);
            } else {
                $this->variantExternalId = strtolower($targetExternalId);
            }
        } else {
            Assert::null($targetType);
            Assert::null($targetExternalId);
            Assert::null($targetSnapshot);
        }
        $this->id = strtolower($id);
        $this->companyId = strtolower($companyId);
        $this->connectionId = strtolower($connectionId);
        $this->listingId = strtolower($listingId);
        $this->revision = $revision;
        $this->expectedRevision = $expectedRevision;
        $this->operation = $operation;
        $this->requestId = strtolower($requestId);
        $this->actorUserId = strtolower($actorUserId);
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
        $this->listingSnapshot = $listingSnapshot;
        $this->targetSnapshot = $targetSnapshot;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getConnectionId(): string
    {
        return $this->connectionId;
    }

    public function getListingId(): string
    {
        return $this->listingId;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getExpectedRevision(): int
    {
        return $this->expectedRevision;
    }

    public function getOperation(): ListingMappingOperation
    {
        return $this->operation;
    }

    public function getTargetType(): ?string
    {
        return null !== $this->productExternalId ? 'product' : (null !== $this->variantExternalId ? 'variant' : null);
    }

    public function getTargetExternalId(): ?string
    {
        return $this->productExternalId ?? $this->variantExternalId;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getActorUserId(): string
    {
        return $this->actorUserId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function getListingSnapshot(): array
    {
        return $this->listingSnapshot;
    }

    /** @return array<string, mixed>|null */
    public function getTargetSnapshot(): ?array
    {
        return $this->targetSnapshot;
    }
}
