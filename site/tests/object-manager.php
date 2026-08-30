<?php

declare(strict_types=1);

/*
 * Загрузчик EntityManager для phpstan/phpstan-doctrine.
 *
 * Без него расширение не знает маппинга: `find()` остаётся `object`, QueryBuilder
 * и DQL не типизируются, а обращения к `$this->_em` в Repository выглядят как
 * несуществующие свойства. Соединение с БД здесь не открывается — расширению
 * нужна метадата, а не данные.
 *
 * Окружение пришпилено к `test`, а не взято из APP_ENV, по двум причинам.
 * Во-первых, результат анализа не должен зависеть от того, что стоит в окружении
 * запускающего. Во-вторых, `containerXmlPath` в phpstan.dist.neon указывает на
 * test-контейнер, и метадата обязана быть из того же окружения: `when@test`
 * в config/packages/doctrine.yaml добавляет маппинг TestFixtures
 * (tests/Fixtures/Doctrine), которого в dev нет — 117 маппингов против 116.
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '1';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
