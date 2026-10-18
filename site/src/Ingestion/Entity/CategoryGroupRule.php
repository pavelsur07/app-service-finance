<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Enum\CategoryGroupRuleConfidence;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\CategoryGroupRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Правило подсказки группы для нового типа начисления: регулярное выражение по
 * `name` и `description` из справочника источника, без учёта регистра.
 *
 * Правила — данные, а не код: их правят без деплоя. Первое совпавшее по
 * `priority` (меньше — раньше) выигрывает.
 */
#[ORM\Entity(repositoryClass: CategoryGroupRuleRepository::class)]
#[ORM\Table(name: 'ingest_category_group_rules')]
#[ORM\Index(name: 'idx_ingest_category_group_rule_source', columns: ['source', 'active', 'priority'])]
class CategoryGroupRule
{
    public const PATTERN_MAX_LENGTH = 1000;
    public const NOTE_MAX_LENGTH = 255;
    private const DELIMITER = '~';

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 64, enumType: IngestSource::class)]
    private IngestSource $source;

    #[ORM\Column(type: Types::INTEGER)]
    private int $priority;

    #[ORM\Column(type: Types::STRING, length: self::PATTERN_MAX_LENGTH)]
    private string $pattern;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $groupCode;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: CategoryGroupRuleConfidence::class)]
    private CategoryGroupRuleConfidence $confidence;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $note;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        IngestSource $source,
        int $priority,
        string $pattern,
        string $groupCode,
        CategoryGroupRuleConfidence $confidence,
        bool $active = true,
        ?string $note = null,
        ?string $id = null,
    ) {
        Assert::notEmpty(trim($pattern));
        Assert::maxLength($pattern, self::PATTERN_MAX_LENGTH);
        Assert::notContains($pattern, self::DELIMITER, 'Pattern must not contain the regex delimiter "~".');
        Assert::regex($groupCode, '/^[a-z][a-z0-9_]{0,63}$/');
        if (null !== $note) {
            Assert::maxLength($note, self::NOTE_MAX_LENGTH);
        }
        if (null !== $id) {
            Assert::uuid($id);
        }

        $now = new \DateTimeImmutable();

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->source = $source;
        $this->priority = $priority;
        $this->pattern = $pattern;
        $this->groupCode = $groupCode;
        $this->confidence = $confidence;
        $this->active = $active;
        $this->note = $note;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSource(): IngestSource
    {
        return $this->source;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Выражение для `preg_match`: без учёта регистра, UTF-8 (с флагом `u` PCRE
     * учитывает регистр и `\b` для кириллицы). Разделитель в шаблоне запрещён
     * конструктором, экранировать нечего.
     */
    public function regex(): string
    {
        return self::DELIMITER.$this->pattern.self::DELIMITER.'iu';
    }

    public function getGroupCode(): string
    {
        return $this->groupCode;
    }

    public function getConfidence(): CategoryGroupRuleConfidence
    {
        return $this->confidence;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
