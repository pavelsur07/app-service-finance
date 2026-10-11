<?php

declare(strict_types=1);

namespace App\Tests\Support\DoctrineDeprecations;

use Doctrine\Deprecations\Deprecation;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Гейт депрекейшенов Doctrine: новый deprecation, которого нет в baseline.php,
 * роняет прогон.
 *
 * Обычный failOnDeprecation их не видит: doctrine/deprecations вызывает
 * `@trigger_error`, а PHPUnit подавленные депрекейшены отбрасывает. Включать
 * ignoreSuppressionOfDeprecations нельзя — туда же хлынут все подавленные
 * депрекейшены Symfony и других вендоров. Поэтому режим Doctrine выставляется
 * здесь явно — учёт плюс логгер, без trigger_error — до загрузки ядра, и
 * `DOCTRINE_DEPRECATIONS=trigger` от FrameworkBundle::boot() его уже не меняет.
 *
 * Падение — PHPUnit warning раннера: failOnPhpunitWarning включён по умолчанию.
 * Известный долг перечислен в baseline.php; убрали причину — уберите строку.
 */
final class DoctrineDeprecationsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $collector = new DeprecationCollector();
        Deprecation::enableTrackingDeprecations();
        Deprecation::enableWithPsrLogger($collector);

        /** @var array<string, string> $baseline */
        $baseline = require __DIR__.'/baseline.php';

        $facade->registerSubscribers(
            new class($collector) implements PreparedSubscriber {
                public function __construct(private readonly DeprecationCollector $collector)
                {
                }

                public function notify(Prepared $event): void
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

                    $lines = [sprintf('Новые депрекейшены Doctrine (%d) — исправьте вызов или осознанно внесите ссылку в tests/Support/DoctrineDeprecations/baseline.php:', count($new))];
                    foreach ($new as $link => $seen) {
                        $lines[] = sprintf("- %s\n  %s\n  место: %s\n  тест: %s", $link, $seen['message'], $seen['where'], $seen['test']);
                    }

                    EventFacade::emitter()->testRunnerTriggeredPhpunitWarning(implode("\n", $lines));
                }
            },
        );
    }
}
