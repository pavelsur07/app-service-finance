<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\CategoryGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Группа статей источника из закрытого списка («Услуги партнёров», «Услуги FBO»…).
 *
 * `label` — как группа называется у нас и в маппингах, `ozonLabel` — как её
 * называет отчёт кабинета, если иначе («Компенсации и декомпенсации»).
 * Справочник общий для всех компаний, `companyId` нет.
 */
#[ORM\Entity(repositoryClass: CategoryGroupRepository::class)]
#[ORM\Table(name: 'ingest_category_groups')]
#[ORM\UniqueConstraint(name: 'uniq_ingest_category_group_code', columns: ['source', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_ingest_category_group_label', columns: ['source', 'label'])]
class CategoryGroup
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $label;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $ozonLabel;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder;

    public function __construct(
        IngestSource $source,
        string $code,
        string $label,
        int $sortOrder,
        ?string $ozonLabel = null,
        ?string $id = null,
    ) {
        Assert::regex($code, '/^[a-z][a-z0-9_]{0,63}$/');
        Assert::notEmpty(trim($label));
        Assert::regex($label, '/^[^\p{Cc}]+$/u', 'Group label must not contain control characters.');
        if (null !== $id) {
            Assert::uuid($id);
        }

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->source = $source;
        $this->code = $code;
        $this->label = trim($label);
        $this->ozonLabel = null !== $ozonLabel && '' !== trim($ozonLabel) ? trim($ozonLabel) : null;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getOzonLabel(): ?string
    {
        return $this->ozonLabel;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
