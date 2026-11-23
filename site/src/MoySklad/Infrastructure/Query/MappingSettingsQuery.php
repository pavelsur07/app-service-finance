<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\MoySklad\Application\DTO\MappingSettings;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class MappingSettingsQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function get(string $companyId, string $connectionId, string $marketplace): MappingSettings
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::inArray($marketplace, ['ozon', 'wildberries']);
        $row = $this->db->fetchAssociative('SELECT wb_trailing_w, version FROM moysklad_mapping_settings WHERE company_id=? AND connection_id=? AND marketplace=?', [$companyId, $connectionId, $marketplace]);

        return false === $row ? new MappingSettings() : new MappingSettings((bool) $row['wb_trailing_w'], (int) $row['version']);
    }
}
