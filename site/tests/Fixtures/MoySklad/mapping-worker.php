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
/** @var array{company:string,connection:string,listing:string,product:string,request:string,application:string,mode?:string,targetType?:string,target?:string,guard?:array{previewId:string,ruleVersion:string,sourceFingerprint:string,sizeCharacteristicId:?string}} $data */
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
    } elseif (str_starts_with($data['mode'] ?? '', 'sync_')) {
        $http = new Symfony\Component\HttpClient\MockHttpClient(function (string $method, string $url) use ($db, $data): Symfony\Component\HttpClient\Response\MockResponse {
            if (str_ends_with($data['mode'], '_paused')) {
                $db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?),hashtext(?))', ['mapping-test-pause', $data['application']]);
            }
            $archive = str_contains($data['mode'], 'archive');
            $rows = str_contains($url, '/entity/product?') && str_contains($url, $archive ? 'archived%3Dtrue' : 'archived%3Dfalse') ? [
                ['id' => $archive ? $data['product'] : '99999999-9999-4999-8999-000000000001', 'accountId' => 'aaaaaaaa-aaaa-4aaa-8aaa-000000000001', 'meta' => ['type' => 'product'],
                    'name' => 'Duplicate', 'externalCode' => 'DUP', 'article' => '001', 'archived' => $archive, 'variantsCount' => 0, 'updated' => '2026-11-15 00:00:00'],
            ] : [];

            return new Symfony\Component\HttpClient\Response\MockResponse(json_encode(['meta' => ['size' => count($rows), 'limit' => 100, 'offset' => 0], 'rows' => $rows], \JSON_THROW_ON_ERROR));
        });
        $sync = new App\MoySklad\Application\Action\SyncCatalogAction($container->get(EntityManagerInterface::class),
            $container->get(App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository::class),
            $container->get(App\MoySklad\Infrastructure\Repository\MoySkladProductRepository::class),
            $container->get(App\MoySklad\Infrastructure\Repository\MoySkladVariantRepository::class),
            $container->get(App\MoySklad\Infrastructure\Repository\MoySkladSyncCursorRepository::class),
            $container->get(App\MoySklad\Infrastructure\Repository\MoySkladSyncRunRepository::class),
            new App\MoySklad\Infrastructure\Api\MoySkladClient($http, 'https://example.test/api/remap/1.2'),
            new App\MoySklad\Application\CatalogPageParser(), $container->get(App\MoySklad\Infrastructure\Security\ConnectionTokenCodec::class), new Psr\Log\NullLogger(),
            $container->get(App\MoySklad\Infrastructure\Lock\MoySkladCatalogLock::class));
        if ('sync_duplicate_handler' === $data['mode']) {
            $bus = new class implements Symfony\Component\Messenger\MessageBusInterface {
                public int $calls = 0;

                public function dispatch(object $message, array $stamps = []): Symfony\Component\Messenger\Envelope
                {
                    ++$this->calls;

                    return new Symfony\Component\Messenger\Envelope($message, $stamps);
                }
            };
            $records = new Monolog\Handler\TestHandler();
            $logger = new Monolog\Logger('sync-duplicate-test', [$records]);
            (new App\MoySklad\MessageHandler\SyncCatalogHandler($sync, $bus, $logger))(
                new App\MoySklad\Message\SyncCatalogMessage($data['company'], $data['connection'], 3));
            echo 'success:handler:'.$bus->calls.':'.(int) $records->hasErrorRecords()."\n";
        } else {
            echo ($sync($data['company'], $data['connection']) ? 'success:imported' : 'success:skipped')."\n";
        }
    } elseif ('source' === ($data['mode'] ?? '')) {
        $db->executeStatement("UPDATE marketplace_listings SET supplier_sku='CHANGED' WHERE company_id=? AND id=?", [$data['company'], $data['listing']]);
        echo "success:source\n";
    } elseif ('archive' === ($data['mode'] ?? 'set')) {
        $db->executeStatement('UPDATE moysklad_products SET archived = TRUE WHERE company_id=? AND connection_id=? AND external_id=?', [$data['company'], $data['connection'], $data['product']]);
        echo "success:archive\n";
    } elseif ('variant' === ($data['mode'] ?? 'set')) {
        $db->executeStatement("INSERT INTO moysklad_variants (id,company_id,connection_id,external_id,product_external_id,name,external_code,code,article,characteristics,archived,source_updated_at,loaded_at) VALUES (?,?,?,?,?,'Child','external','V','001-M','[]',FALSE,NOW(),NOW())",
            [Uuid::uuid7()->toString(), $data['company'], $data['connection'], Uuid::uuid7()->toString(), $data['product']]);
        echo "success:variant\n";
    } else {
        $action = $container->get(ChangeListingMappingAction::class);
        if (in_array($data['mode'] ?? 'set', ['set_paused', 'auto_paused'], true)) {
            // Pause the real Action after it validates/locks the target, before
            // inserting its revision, without changing production code.
            $clock = new class($db, $data['application']) implements Symfony\Component\Clock\ClockInterface {
                public function __construct(private Doctrine\DBAL\Connection $db, private string $application)
                {
                }

                public function now(): DateTimeImmutable
                {
                    $previous = $this->db->fetchOne("SELECT current_setting('lock_timeout')");
                    $this->db->fetchOne("SELECT set_config('lock_timeout','0',true)");
                    $this->db->fetchOne('SELECT pg_advisory_xact_lock(hashtext(?),hashtext(?))', ['mapping-test-pause', $this->application]);
                    $this->db->fetchOne("SELECT set_config('lock_timeout',?,true)", [$previous]);

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
                $container->get(App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery::class), $clock,
                $container->get(App\MoySklad\Infrastructure\Lock\MoySkladCatalogLock::class),
                $container->get(App\MoySklad\Domain\AutomaticListingMappingPolicy::class),
                $container->get(App\MoySklad\Infrastructure\Query\AutomaticMappingCandidatesQuery::class),
                $container->get(App\MoySklad\Infrastructure\Query\MappingCharacteristicsQuery::class));
        }
        $result = $action(new ChangeListingMappingCommand($data['company'], '22222222-2222-4222-8222-000000000001',
            $data['connection'], $data['listing'], ListingMappingOperation::SET, $data['targetType'] ?? 'product', $data['target'] ?? $data['product'], 0, $data['request'],
            isset($data['guard']) ? new App\MoySklad\Application\DTO\AutomaticMappingGuard(...$data['guard']) : null));
        echo 'success:'.$result->revision."\n";
    }
} catch (App\MoySklad\Exception\CatalogSyncException $error) {
    echo 'retry:'.$error->category."\n";
} catch (ListingMappingException $error) {
    echo 'rejected:'.$error->reason."\n";
}
$kernel->shutdown();
