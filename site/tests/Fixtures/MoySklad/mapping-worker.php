<?php

declare(strict_types=1);

use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
/** @var array{company:string,connection:string,listing:string,product:string,request:string,application:string,mode?:string,targetType?:string,target?:string} $data */
$data = json_decode((string) stream_get_contents(\STDIN), true, 512, \JSON_THROW_ON_ERROR);
$db = $container->get(EntityManagerInterface::class)->getConnection();
$db->fetchOne("SELECT set_config('application_name', ?, false)", [$data['application']]);
echo "ready\n";
flush();
try {
    if ('down' === ($data['mode'] ?? 'set')) {
        require_once dirname(__DIR__, 3).'/migrations/Version20261115153000.php';
        $db->beginTransaction();
        try {
            $migration = new DoctrineMigrations\Version20261115153000($db, new Psr\Log\NullLogger());
            $migration->down(new Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $db->commit();
            echo "success:down\n";
        } catch (Doctrine\DBAL\Exception\DriverException|Doctrine\Migrations\Exception\AbortMigration) {
            $db->rollBack();
            echo "rejected:history_not_empty\n";
        }
    } elseif ('archive' === ($data['mode'] ?? 'set')) {
        $db->executeStatement('UPDATE moysklad_products SET archived = TRUE WHERE company_id=? AND connection_id=? AND external_id=?', [$data['company'], $data['connection'], $data['product']]);
        echo "success:archive\n";
    } elseif ('variant' === ($data['mode'] ?? 'set')) {
        $db->executeStatement("INSERT INTO moysklad_variants (id,company_id,connection_id,external_id,product_external_id,name,external_code,code,article,characteristics,archived,source_updated_at,loaded_at) VALUES (?,?,?,?,?,'Child','external','V','001-M','[]',FALSE,NOW(),NOW())",
            [Uuid::uuid7()->toString(), $data['company'], $data['connection'], Uuid::uuid7()->toString(), $data['product']]);
        echo "success:variant\n";
    } else {
        $action = $container->get(ChangeListingMappingAction::class);
        if ('set_paused' === ($data['mode'] ?? 'set')) {
            // Pause the real Action after it validates/locks the target, before
            // inserting its revision, without changing production code.
            $clock = new class($db, $data['application']) implements Symfony\Component\Clock\ClockInterface {
                public function __construct(private Doctrine\DBAL\Connection $db, private string $application)
                {
                }

                public function now(): DateTimeImmutable
                {
                    $this->db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?),hashtext(?))', ['mapping-test-pause', $this->application]);

                    return new DateTimeImmutable();
                }

                public function sleep(float|int $seconds): void
                {
                    usleep((int) ($seconds * 1000000));
                }

                public function withTimeZone(DateTimeZone|string $timezone): static
                {
                    return clone $this;
                }
            };
            $action = new ChangeListingMappingAction($container->get(EntityManagerInterface::class),
                $container->get(App\Marketplace\Facade\MarketplaceFacade::class),
                $container->get(App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository::class),
                $container->get(App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery::class), $clock);
        }
        $result = $action(new ChangeListingMappingCommand($data['company'], '22222222-2222-4222-8222-000000000001',
            $data['connection'], $data['listing'], ListingMappingOperation::SET, $data['targetType'] ?? 'product', $data['target'] ?? $data['product'], 0, $data['request']));
        echo 'success:'.$result->revision."\n";
    }
} catch (ListingMappingException $error) {
    echo 'rejected:'.$error->reason."\n";
}
$kernel->shutdown();
