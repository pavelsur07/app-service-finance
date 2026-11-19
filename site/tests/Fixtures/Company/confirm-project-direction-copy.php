<?php

declare(strict_types=1);

use App\Company\Application\ProjectDirection\CopyProjectDirectionsAction;
use App\Company\Application\ProjectDirection\ProjectDirectionExchange;
use App\Company\Repository\ProjectDirectionCopyMappingRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
if ('test' !== ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '')) {
    throw new RuntimeException('Test worker only.');
}

$input = json_decode(stream_get_contents(\STDIN), true, flags: \JSON_THROW_ON_ERROR);
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$connection = $container->get(Connection::class);
$connection->executeQuery("SELECT set_config('application_name', ?, false)", [$input['name']]);
$projects = $container->get(ProjectDirectionRepository::class);
$copy = new CopyProjectDirectionsAction($connection, $projects, new ProjectDirectionCopyMappingRepository($connection), new ProjectDirectionExchange($projects));
echo "ready\n";
flush();

$result = $copy->confirm($input['source'], $input['target'], $input['sourceRevision'], $input['targetRevision'], []);
echo json_encode($result->errors, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)."\n";
$kernel->shutdown();
