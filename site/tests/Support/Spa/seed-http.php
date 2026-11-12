<?php

declare(strict_types=1);

// Disposable HTTP fixtures only; not a console command or a public endpoint.
use App\Kernel;
use App\Tests\Support\Spa\SpaHttpFixtures;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env.test');
if ('spa_e2e' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) || 'site-postgres' !== parse_url((string) getenv('DATABASE_URL'), \PHP_URL_HOST) || '/spa_auth' !== parse_url((string) getenv('DATABASE_URL'), \PHP_URL_PATH)) {
    throw new RuntimeException('HTTP fixtures require the isolated spa_e2e database.');
}
$kernel = new Kernel('spa_e2e', false);
$kernel->boot();
SpaHttpFixtures::load($kernel->getContainer()->get('doctrine')->getManager());
$kernel->shutdown();
fwrite(\STDOUT, "Isolated HTTP fixtures ready.\n");
