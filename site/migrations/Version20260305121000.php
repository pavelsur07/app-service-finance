<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Ramsey\Uuid\Uuid;

final class Version20260305121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed baseline Wildberries commissioner cost types per company';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('wildberries_commissioner_cost_types')) {
            return;
        }

        $costTypes = [
            'DELIVERY_TO_CUSTOMER' => 'Логистика к клиенту',
            'ACQUIRING' => 'Эквайринг',
            'COMMISSION_WB' => 'Комиссия WB (без НДС)',
            'COMMISSION_WB_VAT' => 'НДС с комиссии WB',
            'PVZ' => 'ПВЗ (выдача/возврат)',
            'STORAGE' => 'Хранение',
            'REBILL_LOGISTICS' => 'Возмещение издержек логистики/складских операций',
            'WITHHOLDINGS' => 'Удержания',
        ];

        $companies = $this->connection->fetchFirstColumn('SELECT id FROM companies');
        $now = new \DateTimeImmutable();

        foreach ($companies as $companyId) {
            foreach ($costTypes as $code => $title) {
                $exists = $this->connection->fetchOne(
                    'SELECT 1 FROM wildberries_commissioner_cost_types WHERE company_id = :company_id AND code = :code',
                    [
                        'company_id' => $companyId,
                        'code' => $code,
                    ],
                    [
                        'company_id' => Types::GUID,
                        'code' => Types::STRING,
                    ],
                );

                if ($exists !== false) {
                    continue;
                }

                $this->connection->insert(
                    'wildberries_commissioner_cost_types',
                    [
                        'id' => Uuid::uuid4()->toString(),
                        'company_id' => $companyId,
                        'code' => $code,
                        'title' => $title,
                        'is_active' => true,
                        'created_at' => $now,
                    ],
                    [
                        'id' => Types::GUID,
                        'company_id' => Types::GUID,
                        'code' => Types::STRING,
                        'title' => Types::STRING,
                        'is_active' => Types::BOOLEAN,
                        'created_at' => Types::DATETIME_IMMUTABLE,
                    ],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('wildberries_commissioner_cost_types')) {
            return;
        }

        $codes = [
            'DELIVERY_TO_CUSTOMER',
            'ACQUIRING',
            'COMMISSION_WB',
            'COMMISSION_WB_VAT',
            'PVZ',
            'STORAGE',
            'REBILL_LOGISTICS',
            'WITHHOLDINGS',
        ];

        $this->connection->executeStatement(
            'DELETE FROM wildberries_commissioner_cost_types WHERE code IN (:codes)',
            [
                'codes' => $codes,
            ],
            [
                'codes' => ArrayParameterType::STRING,
            ],
        );
    }
}
