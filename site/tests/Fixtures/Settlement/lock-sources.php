<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
assert($db instanceof Doctrine\DBAL\Connection);
$schema = (string) getenv('SETTLEMENT_LOCK_SCHEMA');
if (!preg_match('/^settlement_lock_[a-f0-9]+$/D', $schema)) {
    throw new RuntimeException('Invalid test schema.');
}
$db->executeStatement('SET search_path TO '.$schema);
$db->fetchOne("SELECT set_config('application_name', :name, false)", ['name' => $schema]);
$db->beginTransaction();
echo "ready\n";
flush();
$repo = $kernel->getContainer()->get('test.service_container')->get(App\Cash\Repository\Transaction\CashTransactionRepository::class);
if ('guard' === getenv('SETTLEMENT_LOCK_MODE')) {
    $repo->getSettlementSourceForUpdate((string) getenv('SETTLEMENT_LOCK_COMPANY'), (string) getenv('SETTLEMENT_LOCK_SOURCE'));
    try {
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        $dispatcher->dispatch(new App\Cash\Domain\Event\CashSettlementSourceChanging((string) getenv('SETTLEMENT_LOCK_COMPANY'), (string) getenv('SETTLEMENT_LOCK_SOURCE'), true));
        echo "allowed\n";
        $db->rollBack();
        $kernel->shutdown();
        throw new RuntimeException('Expected settlement guard rejection.');
    } catch (App\Settlement\Exception\SettlementSourceInUseException) {
        echo "blocked\n";
        $db->rollBack();
        $kernel->shutdown();

        return;
    }
}
$repo->lockSettlementSourcesForAccount((string) getenv('SETTLEMENT_LOCK_COMPANY'), (string) getenv('SETTLEMENT_LOCK_ACCOUNT'), ['file-b', 'file-a']);
echo "locked\n";
flush();
fgets(\STDIN);
$db->rollBack();
$kernel->shutdown();
