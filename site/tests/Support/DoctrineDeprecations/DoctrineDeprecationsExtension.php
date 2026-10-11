<?php

declare(strict_types=1);

namespace App\Tests\Support\DoctrineDeprecations;

use Doctrine\Deprecations\Deprecation;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Гейт депрекейшенов Doctrine: deprecation с ссылкой, которой нет в
 * baseline.php, роняет прогон.
 *
 * Обычный failOnDeprecation их не видит: doctrine/deprecations вызывает
 * `@trigger_error`, а PHPUnit подавленные депрекейшены отбрасывает. Включать
 * ignoreSuppressionOfDeprecations нельзя — туда же хлынут все подавленные
 * депрекейшены Symfony и других вендоров. Поэтому режим Doctrine (PSR-логгер,
 * без trigger_error) выставляется здесь явно до загрузки ядра, и
 * `DOCTRINE_DEPRECATIONS=trigger` от FrameworkBundle::boot() его уже не меняет.
 *
 * Гранулярность — ссылка: гейт ловит новые ВИДЫ депрекейшенов, а не новые
 * вызовы известных. Пока ссылка в baseline, новые вызовы того же вида проходят.
 * Ловится любой пакет на doctrine/deprecations, включая vendor→vendor вызовы.
 * Тесты в отдельном процессе (#[RunInSeparateProcess]) расширение не видит.
 *
 * Падение — PHPUnit warning раннера (failOnPhpunitWarning включён по
 * умолчанию). Event\Facade::emitter() — @internal API PHPUnit 11.5: при
 * обновлении PHPUnit путь падения перепроверить внедрённым Deprecation::trigger().
 */
final class DoctrineDeprecationsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $collector = new DeprecationCollector();
        Deprecation::enableWithPsrLogger($collector);

        /** @var array<string, string> $baseline */
        $baseline = require __DIR__.'/baseline.php';

        $facade->registerSubscribers(
            // Suite-уровень — для setUpBeforeClass(); PreparationStarted приходит
            // до setUp(), иначе депрекейшены из setUp ушли бы предыдущему тесту.
            new class($collector) implements StartedSubscriber {
                public function __construct(private readonly DeprecationCollector $collector)
                {
                }

                public function notify(Started $event): void
                {
                    $this->collector->testStarted('suite '.$event->testSuite()->name());
                }
            },
            new class($collector) implements PreparationStartedSubscriber {
                public function __construct(private readonly DeprecationCollector $collector)
                {
                }

                public function notify(PreparationStarted $event): void
                {
                    $this->collector->testStarted($event->test()->id());
                }
            },
            new class($collector, $baseline) implements ExecutionFinishedSubscriber {
                /** @param array<string, string> $baseline */
                public function __construct(
                    private readonly DeprecationCollector $collector,
                    private readonly array $baseline,
                ) {
                }

                public function notify(ExecutionFinished $event): void
                {
                    $new = array_diff_key($this->collector->firstSeen(), $this->baseline);
                    if ([] === $new) {
                        return;
                    }

                    $lines = [sprintf('Новые депрекейшены Doctrine (%d). Исправьте вызов; если источник в vendor или исправление — отдельная задача, внесите ссылку в tests/Support/DoctrineDeprecations/baseline.php с пояснением:', count($new))];
                    foreach ($new as $link => $seen) {
                        $lines[] = sprintf(
                            "- %s (%s)\n  %s\n  вызов: %s\n  в vendor: %s\n  тест: %s",
                            $link,
                            $seen['package'],
                            $seen['message'],
                            $seen['caller'],
                            $seen['trigger'],
                            $seen['test'],
                        );
                    }

                    EventFacade::emitter()->testRunnerTriggeredPhpunitWarning(implode("\n", $lines));
                }
            },
        );
    }
}
