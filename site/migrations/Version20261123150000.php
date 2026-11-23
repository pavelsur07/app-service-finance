<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Решение Владельца 23.11: «Возмещение издержек по перевозке и складским операциям» WB
 * (`wb:field:rebillLogisticCost`) — «Хранение» (СМ3), как статья «Хранение» в ОПиУ, а не логистика
 * до клиента. Переводчик берёт вид из справочника; дни переводятся заново по новой версии правил
 * (`SalesReportTranslator::RULES_VERSION` wb-sales-report-4).
 */
final class Version20261123150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WB rebillLogisticCost source type: logistics_to -> storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE mwb_source_type SET kind = 'storage' WHERE source_type = 'wb:field:rebillLogisticCost' AND kind = 'logistics_to'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE mwb_source_type SET kind = 'logistics_to' WHERE source_type = 'wb:field:rebillLogisticCost' AND kind = 'storage'");
    }
}
