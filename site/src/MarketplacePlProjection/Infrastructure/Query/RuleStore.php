<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Infrastructure\Query;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplacePlProjection\Domain\Rule;
use App\MarketplacePlProjection\Domain\TemplateRule;
use App\MarketplacePlProjection\Enum\RuleOrigin;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

/** Таблица `mpp_rule` через DBAL. Транзакцией владеет вызывающий Action. */
final readonly class RuleStore
{
    private const KEY_MATCH = 'company_id = :company AND kind IS NOT DISTINCT FROM :kind AND channel IS NOT DISTINCT FROM :channel AND source_type IS NOT DISTINCT FROM :source_type';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Rule> */
    public function rules(string $companyId): array
    {
        $rules = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT kind, channel, source_type, pl_category_id, origin, valid_from, template_version FROM mpp_rule WHERE company_id = ? ORDER BY kind, channel, source_type, valid_from',
            [$companyId],
        ) as $row) {
            $rules[] = new Rule(
                null === $row['kind'] ? null : OperationKind::from((string) $row['kind']),
                null === $row['channel'] ? null : (string) $row['channel'],
                null === $row['source_type'] ? null : (string) $row['source_type'],
                null === $row['pl_category_id'] ? null : (string) $row['pl_category_id'],
                RuleOrigin::from((string) $row['origin']),
                (string) $row['valid_from'],
                null === $row['template_version'] ? null : (int) $row['template_version'],
            );
        }

        return $rules;
    }

    /** Базовое `preset`-правило ключа: создать или обновить; правило `custom` того же ключа и даты не трогает. */
    public function savePreset(string $companyId, TemplateRule $key, ?string $plCategoryId, int $templateVersion): void
    {
        $this->connection->executeStatement(
            "INSERT INTO mpp_rule (id, company_id, kind, channel, source_type, valid_from, pl_category_id, origin, template_version, updated_at)
             VALUES (:id, :company, :kind, :channel, :source_type, :valid_from, :category, 'preset', :version, :now)
             ON CONFLICT (company_id, kind, channel, source_type, valid_from) DO UPDATE
             SET pl_category_id = EXCLUDED.pl_category_id, template_version = EXCLUDED.template_version, updated_at = EXCLUDED.updated_at, updated_by = NULL
             WHERE mpp_rule.origin = 'preset'",
            $this->keyParams($companyId, $key) + [
                'id' => Uuid::uuid7()->toString(),
                'valid_from' => Rule::BASE_DATE,
                'category' => $plCategoryId,
                'version' => $templateVersion,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function deletePreset(string $companyId, TemplateRule $key): void
    {
        $this->connection->executeStatement(
            'DELETE FROM mpp_rule WHERE '.self::KEY_MATCH." AND origin = 'preset'",
            $this->keyParams($companyId, $key),
        );
    }

    /** @return array{company: string, kind: ?string, channel: ?string, source_type: ?string} */
    private function keyParams(string $companyId, TemplateRule $key): array
    {
        return ['company' => $companyId, 'kind' => $key->kind?->value, 'channel' => $key->channel, 'source_type' => $key->sourceType];
    }
}
