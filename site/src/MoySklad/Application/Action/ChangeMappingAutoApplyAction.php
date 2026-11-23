<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/** Включает/выключает автоприменение; автор пакетов — тот, кто включил, характеристика — выбранная сейчас. */
final readonly class ChangeMappingAutoApplyAction
{
    public function __construct(private Connection $db, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId, string $connectionId, string $marketplace, bool $enabled, string $actorUserId, ?string $sizeCharacteristicId): void
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($actorUserId);
        Assert::inArray($marketplace, ['ozon', 'wildberries']);
        $this->db->executeStatement('INSERT INTO moysklad_mapping_auto_apply (company_id, connection_id, marketplace, enabled, actor_user_id, size_characteristic_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (company_id, connection_id, marketplace)
            DO UPDATE SET enabled=EXCLUDED.enabled, actor_user_id=EXCLUDED.actor_user_id, size_characteristic_id=EXCLUDED.size_characteristic_id, updated_at=EXCLUDED.updated_at',
            [$companyId, $connectionId, $marketplace, $enabled ? 'true' : 'false', $actorUserId, $sizeCharacteristicId,
                $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v')]);
    }
}
