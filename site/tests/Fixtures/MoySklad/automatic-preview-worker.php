<?php

declare(strict_types=1);

use App\Kernel;
use App\MoySklad\Application\DTO\AutomaticMappingApplyResult;
use App\MoySklad\Application\DTO\AutomaticMappingPreview;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage;

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
if ('test' !== ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '')) {
    throw new RuntimeException('Test worker only.');
}
$input = json_decode(stream_get_contents(\STDIN), true, 512, \JSON_THROW_ON_ERROR);
$kernel = new Kernel('test', true);
$kernel->boot();
$storage = $kernel->getContainer()->get('test.service_container')->get(AutomaticMappingPreviewStorage::class);
echo "ready\n";
flush();
$deadline = microtime(true) + 8;
while (!is_file($input['barrier'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Missing test barrier.');
    }
    usleep(1000);
}
try {
    if (isset($input['attempt'])) {
        $storage->putResult($input['session'], $input['preview']['id'], $input['attempt'], AutomaticMappingApplyResult::fromArray($input['result']));
    } else {
        $storage->put($input['session'], AutomaticMappingPreview::fromArray($input['preview']));
    }
    echo "success\n";
} catch (ListingMappingException $error) {
    echo 'rejected:'.$error->reason."\n";
}
$kernel->shutdown();
