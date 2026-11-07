<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$db = $container->get(Doctrine\ORM\EntityManagerInterface::class)->getConnection();
$db->fetchOne("SELECT set_config('application_name', ?, false)", [(string) getenv('OZON_RACE_KEY')]);
echo "ready\n";
flush();
try {
    if ('handler' === getenv('OZON_RACE_MODE')) {
        $container->get(App\Marketplace\Ozon\MessageHandler\ProcessOzonRealizationHandler::class)(new App\Marketplace\Message\ProcessOzonRealizationMessage(
            (string) getenv('OZON_RACE_COMPANY'), (string) getenv('OZON_RACE_CONNECTION'), (string) getenv('OZON_RACE_DOCUMENT'), 2026, 1,
        ));
    } else {
        $container->get(App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction::class)(
            (string) getenv('OZON_RACE_COMPANY'), (string) getenv('OZON_RACE_DOCUMENT'),
        );
    }
    echo "done\n";
} catch (DomainException $e) {
    echo 'rejected:'.$e->getMessage()."\n";
}
$kernel->shutdown();
