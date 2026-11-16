<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\DTO\MappingSettings;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Query\MappingSettingsQuery;
use Doctrine\DBAL\Connection;

final readonly class ChangeMappingSettingsAction
{
    public function __construct(private Connection $db, private MappingSettingsQuery $settings)
    {
    }

    public function __invoke(string $companyId, string $connectionId, bool $enabled): MappingSettings
    {
        return $this->db->transactional(function () use ($companyId, $connectionId, $enabled): MappingSettings {
            // Same connection row as guarded mapping writers; version stays stable until their commit.
            if (false === $this->db->fetchOne('SELECT id FROM moysklad_connections WHERE company_id=? AND id=? FOR UPDATE', [$companyId, $connectionId])) {
                throw new ListingMappingException('not_found', 'Подключение не найдено.');
            }
            $current = $this->settings->get($companyId, $connectionId, 'wildberries');
            if ($current->wbTrailingW === $enabled) {
                return $current;
            }
            $this->db->executeStatement("INSERT INTO moysklad_mapping_settings (company_id, connection_id, marketplace, wb_trailing_w, version)
                VALUES (?, ?, 'wildberries', ?, 1) ON CONFLICT (company_id, connection_id, marketplace)
                DO UPDATE SET wb_trailing_w=EXCLUDED.wb_trailing_w, version=moysklad_mapping_settings.version+1",
                [$companyId, $connectionId, $enabled ? 'true' : 'false']);

            return $this->settings->get($companyId, $connectionId, 'wildberries');
        });
    }
}
