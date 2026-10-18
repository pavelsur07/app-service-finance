<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Закрытый список групп статей и правила подсказки группы (Ingestion).
 *
 * Метки групп — как в текущих маппингах прода, ничего не переименовывается.
 * UUID фиксированы и совпадают с `App\Ingestion\Domain\OzonCategoryGroupSeed`
 * (оттуда тестовый DbReset восстанавливает строки после TRUNCATE).
 * Правила выверены по группам отчёта кабинета Ozon «Детализация начислений»
 * (docs/tasks/ozon-accrual-types-dictionary/stages/stage-0.md): на эталоне из 23
 * типов совпадают 22, промах — 29 LastMileCourier (в описании нет «партнёр»,
 * тип размечен каталогом).
 */
final class Version20261018160000 extends AbstractMigration
{
    /**
     * @var list<array{id: string, code: string, label: string, ozon_label: ?string, sort: int}>
     */
    private const GROUPS = [
        ['id' => '82fbebca-22d5-5fd3-a287-0009e4efa796', 'code' => 'sales', 'label' => 'Продажи', 'ozon_label' => null, 'sort' => 100],
        ['id' => 'e0159dad-d870-519e-a079-913c9e3949b9', 'code' => 'returns', 'label' => 'Возвраты', 'ozon_label' => null, 'sort' => 200],
        ['id' => '79b90d0a-2b98-5434-9021-dd34850fcde2', 'code' => 'commission', 'label' => 'Вознаграждение Ozon', 'ozon_label' => null, 'sort' => 300],
        ['id' => 'db7285bd-0f4a-5b8a-b717-4abecc0fcb36', 'code' => 'delivery', 'label' => 'Услуги доставки', 'ozon_label' => null, 'sort' => 400],
        ['id' => '4d80ff52-6e86-5f65-836a-7225ef6fda08', 'code' => 'partners', 'label' => 'Услуги партнёров', 'ozon_label' => null, 'sort' => 500],
        ['id' => '5ca1538b-308e-5433-b33a-60f0234772d3', 'code' => 'fbo', 'label' => 'Услуги FBO', 'ozon_label' => null, 'sort' => 600],
        ['id' => 'feb2e593-5f94-5348-904b-a3a51b5d5a65', 'code' => 'promotion', 'label' => 'Продвижение и реклама', 'ozon_label' => null, 'sort' => 700],
        ['id' => '8fb95d44-8de5-5057-b591-fdee85ba87a3', 'code' => 'compensation', 'label' => 'Компенсации', 'ozon_label' => 'Компенсации и декомпенсации', 'sort' => 800],
        ['id' => '4a9b75d2-fa3b-590b-99c1-f9aec6fba1f0', 'code' => 'other', 'label' => 'Другие услуги и штрафы', 'ozon_label' => null, 'sort' => 900],
        ['id' => '4e0c9b4d-4620-5f1e-a5d5-02763322ce86', 'code' => 'unclassified', 'label' => 'Требует классификации', 'ozon_label' => null, 'sort' => 9000],
    ];

    /**
     * @var list<array{id: string, priority: int, group: string, confidence: string, pattern: string, note: ?string}>
     */
    private const RULES = [
        ['id' => 'cd80086c-ffd8-52b8-b10c-f9e2381db2a2', 'priority' => 10, 'group' => 'compensation', 'confidence' => 'high', 'pattern' => 'компенсац|compensation', 'note' => 'Компенсации и декомпенсации'],
        ['id' => '5eab157c-b721-5bfb-9c57-9258a64b3c28', 'priority' => 20, 'group' => 'partners', 'confidence' => 'high', 'pattern' => 'партн[её]р|agent\b|эквайринг|acquiring|звёздн|звездн|stars|страхован|insurance', 'note' => 'Ozon: страхование и «Звёздные товары» — услуги партнёров'],
        ['id' => '5fdbaab2-09de-57fc-af64-d269cbcbede7', 'priority' => 30, 'group' => 'promotion', 'confidence' => 'high', 'pattern' => 'реклам|продвиж|отзыв|review|promotion|marketing|оплата за клик|payperclick|пуш|push|brand|бренд|подписка premium|premiumsubscription|баннер|трафарет', 'note' => 'Ozon: подписка Premium — продвижение'],
        ['id' => 'c94f3f16-f8ae-5794-a3db-64db56aa4619', 'priority' => 40, 'group' => 'other', 'confidence' => 'high', 'pattern' => 'штраф|defect|fine|благотвор|charity|утилиз|disposal|досрочн|earlypayment|рассрочк|installment|маркировк|label|корректир|correction|кешб|кэшб|cashback|rfbs|материал|packagecost|нерекомендованн|слот', 'note' => null],
        ['id' => '9174c2c9-b42e-526b-a49c-ac003b928fbe', 'priority' => 50, 'group' => 'fbo', 'confidence' => 'high', 'pattern' => 'склад|размещ|placement|supply|при[её]мк|crossdock|кросс-док|вывоз|sellerreturns|itempacking|грузомест', 'note' => null],
        ['id' => '208f6ff2-7ce4-5f3b-841c-53498fb4eb6f', 'priority' => 60, 'group' => 'delivery', 'confidence' => 'high', 'pattern' => 'логистик|logistic|доставк|delivery|drop-off|магистрал|backward|shipment|курьер|courier', 'note' => null],
        ['id' => 'c1de3c26-aade-53c7-b617-1dfd65d9b476', 'priority' => 90, 'group' => 'promotion', 'confidence' => 'medium', 'pattern' => 'premium|премиум|analytics|аналитик', 'note' => 'Слабый сигнал: только подсказка'],
    ];

    public function getDescription(): string
    {
        return 'Add Ingestion category groups and group suggestion rules with Ozon seed';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('CREATE TABLE ingest_category_groups (id UUID NOT NULL, source VARCHAR(64) NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(255) NOT NULL, ozon_label VARCHAR(255) DEFAULT NULL, sort_order INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_category_group_code ON ingest_category_groups (source, code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ingest_category_group_label ON ingest_category_groups (source, label)');

        $this->addSql('CREATE TABLE ingest_category_group_rules (id UUID NOT NULL, source VARCHAR(64) NOT NULL, priority INT NOT NULL, pattern VARCHAR(1000) NOT NULL, group_code VARCHAR(64) NOT NULL, confidence VARCHAR(16) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_ingest_category_group_rule_source ON ingest_category_group_rules (source, active, priority)');

        foreach (self::GROUPS as $group) {
            $this->addSql(
                'INSERT INTO ingest_category_groups (id, source, code, label, ozon_label, sort_order) VALUES (:id, :source, :code, :label, :ozonLabel, :sortOrder)',
                ['id' => $group['id'], 'source' => 'ozon', 'code' => $group['code'], 'label' => $group['label'], 'ozonLabel' => $group['ozon_label'], 'sortOrder' => $group['sort']],
            );
        }

        foreach (self::RULES as $rule) {
            $this->addSql(
                "INSERT INTO ingest_category_group_rules (id, source, priority, pattern, group_code, confidence, active, note, created_at, updated_at)
                 VALUES (:id, :source, :priority, :pattern, :groupCode, :confidence, true, :note, (now() AT TIME ZONE 'Europe/Moscow'), (now() AT TIME ZONE 'Europe/Moscow'))",
                ['id' => $rule['id'], 'source' => 'ozon', 'priority' => $rule['priority'], 'pattern' => $rule['pattern'], 'groupCode' => $rule['group'], 'confidence' => $rule['confidence'], 'note' => $rule['note']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIfNotPostgres();

        $this->addSql('DROP TABLE ingest_category_group_rules');
        $this->addSql('DROP TABLE ingest_category_groups');
    }

    private function abortIfNotPostgres(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof PostgreSQLPlatform,
            sprintf('Migration %s supports only PostgreSQL; got platform "%s".', self::class, $platform::class),
        );
    }
}
