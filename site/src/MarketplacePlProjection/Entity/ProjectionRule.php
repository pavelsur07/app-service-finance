<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Правило компании «операция канона → статья ОПиУ» (`mpp_rule`). Ключ — вид (`kind`, опционально
 * с каналом) либо код источника канала (`source_type`, вид пуст); `pl_category_id = NULL` —
 * «исключено из ОПиУ». Одно правило на ключ и дату начала: unique
 * `(company_id, kind, channel, source_type, valid_from) NULLS NOT DISTINCT` и CHECK формы ключа —
 * в миграции Version20261116100000. Строки пишет RuleStore через DBAL. Только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mpp_rule')]
#[ORM\Index(name: 'idx_mpp_rule_company_category', columns: ['company_id', 'pl_category_id'])]
class ProjectionRule
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $kind;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $channel;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $sourceType;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $plCategoryId;

    /** preset | custom */
    #[ORM\Column(type: 'string', length: 16)]
    private string $origin;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $templateVersion;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $updatedBy;
}
