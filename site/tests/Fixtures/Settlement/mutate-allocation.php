<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$db = $container->get(Doctrine\ORM\EntityManagerInterface::class)->getConnection();
$schema = (string) getenv('SETTLEMENT_LOCK_SCHEMA');
if (!preg_match('/^settlement_lock_[a-f0-9]+$/D', $schema)) {
    throw new RuntimeException('Invalid test schema.');
}
$db->executeStatement('SET search_path TO '.$schema);
$db->fetchOne("SELECT set_config('application_name', ?, false)", [$schema]);
echo "ready\n";
flush();
try {
    $company = (string) getenv('SETTLEMENT_LOCK_COMPANY');
    $actor = (string) getenv('SETTLEMENT_LOCK_ACTOR');
    if ('reverse' === getenv('SETTLEMENT_LOCK_MODE')) {
        $result = $container->get(App\Settlement\Application\ReverseSettlementAllocationAction::class)($company, $actor, (string) getenv('SETTLEMENT_LOCK_ALLOCATION'), new App\Settlement\Application\DTO\ReverseSettlementInput(Ramsey\Uuid\Uuid::uuid7()->toString(), new DateTimeImmutable('2026-11-03'), 'Отмена'));
        echo 'success:'.$result->documentId."\n";
    } else {
        $id = $container->get(App\Settlement\Application\AllocateSettlementAction::class)($company, $actor, new App\Settlement\Application\DTO\AllocateSettlementInput(Ramsey\Uuid\Uuid::uuid7()->toString(), (string) getenv('SETTLEMENT_LOCK_DEBT'), (string) getenv('SETTLEMENT_LOCK_PAYMENT'), App\Shared\Domain\ValueObject\Money::fromString('30.00', 'RUB'), new DateTimeImmutable('2026-11-02')));
        echo 'success:'.$id."\n";
    }
} catch (DomainException $e) {
    echo 'rejected:'.$e->getMessage()."\n";
}
$kernel->shutdown();
