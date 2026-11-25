<?php

declare(strict_types=1);

use App\Cash\Application\Report\IntragroupDecisionAction;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Cash\Exception\IntragroupDecisionException;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
[, $activeCompanyId, $userId, $decisionId, $outId, $inId] = $argv;
$container->get(EntityManagerInterface::class)->getConnection()->fetchOne(
    "SELECT set_config('application_name', ?, false)", ['intragroup-'.$decisionId],
);
echo "ready\n";
flush();
try {
    $container->get(IntragroupDecisionAction::class)->save($activeCompanyId, $userId, $decisionId, IntragroupDecisionType::PAIR, $outId, $inId);
    echo "saved\n";
} catch (IntragroupDecisionException $exception) {
    echo $exception->getMessage()."\n";
} finally {
    $kernel->shutdown();
}
