<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\DTO\AutomaticMappingApplyItem;
use App\MoySklad\Application\DTO\AutomaticMappingApplyResult;
use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Application\DTO\AutomaticMappingPreview;
use App\MoySklad\Enum\AutomaticMappingApplyStatus;
use App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class AutomaticMappingPreviewStorageConcurrencyTest extends KernelTestCase
{
    public function testParallelPreviewsAndAttemptsKeepEveryAcknowledgedPayload(): void
    {
        self::bootKernel();
        $storage = self::getContainer()->get(AutomaticMappingPreviewStorage::class);
        $session = Uuid::uuid7()->toString();
        $company = Uuid::uuid7()->toString();
        $actor = Uuid::uuid7()->toString();
        $connection = Uuid::uuid7()->toString();
        $previews = [];
        foreach (range(1, 2) as $i) {
            $previews[] = new AutomaticMappingPreview(Uuid::uuid7()->toString(), $company, $actor, $connection, 'ozon', time(), time() + 900, AutomaticMappingGuard::RULE_VERSION, '', 1, null, [], []);
        }
        $this->race(array_map(static fn (AutomaticMappingPreview $p): array => ['session' => $session, 'preview' => $p->toArray()], $previews));
        foreach ($previews as $preview) {
            $storage->put($session, $preview); // Explicit retry of a 409 must keep both previews.
            self::assertEquals($preview, $storage->get($session, $preview->id, $company, $actor, $connection, 'ozon'));
        }
        $preview = $previews[0];
        $results = [];
        $inputs = [];
        foreach (range(1, 2) as $i) {
            $attempt = Uuid::uuid7()->toString();
            $results[$attempt] = $result = new AutomaticMappingApplyResult([new AutomaticMappingApplyItem(Uuid::uuid7()->toString(), AutomaticMappingApplyStatus::CONFLICT, 'conflict-'.$i)]);
            $inputs[] = ['session' => $session, 'preview' => $preview->toArray(), 'attempt' => $attempt, 'result' => $result->toArray()];
        }
        $this->race($inputs);
        foreach ($results as $attempt => $result) {
            $storage->putResult($session, $preview->id, $attempt, $result);
            self::assertEquals($result, $storage->getResult($session, $preview->id, $attempt, $company, $actor, $connection, 'ozon'));
        }
    }

    public function testTransientRedisReadFailureCannotOverwriteAcknowledgedIndex(): void
    {
        $redis = new class extends \Redis {
            public bool $failRead = false;

            /** @param list<string> $keys
             * @return array<string|false>|false
             */
            public function mget(array $keys): array|false
            {
                return $this->failRead ? false : parent::mget($keys);
            }
        };
        $redis->connect('site-redis');
        $clock = new \Symfony\Component\Clock\MockClock();
        $storage = new AutomaticMappingPreviewStorage(new \App\MoySklad\Infrastructure\Cache\AutomaticMappingRedisAdapter($redis, 'automatic-test-'.Uuid::uuid7()->toString()),
            new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()), $clock);
        $company = Uuid::uuid7()->toString();
        $actor = Uuid::uuid7()->toString();
        $connection = Uuid::uuid7()->toString();
        $first = new AutomaticMappingPreview(Uuid::uuid7()->toString(), $company, $actor, $connection, 'ozon', time(), time() + 900, AutomaticMappingGuard::RULE_VERSION, '', 1, null, [], []);
        $second = new AutomaticMappingPreview(Uuid::uuid7()->toString(), $company, $actor, $connection, 'ozon', time(), time() + 900, AutomaticMappingGuard::RULE_VERSION, '', 1, null, [], []);
        try {
            $storage->put('private-session', $first);
            $redis->failRead = true;
            try {
                $storage->put('private-session', $second);
                self::fail('Broken index read must not overwrite the old index.');
            } catch (\App\MoySklad\Exception\ListingMappingException $error) {
                self::assertSame(503, $error->getStatusCode());
            }
            $redis->failRead = false;
            self::assertEquals($first, $storage->get('private-session', $first->id, $company, $actor, $connection, 'ozon'));
            $storage->put('private-session', $second);
            self::assertEquals($first, $storage->get('private-session', $first->id, $company, $actor, $connection, 'ozon'));
            self::assertEquals($second, $storage->get('private-session', $second->id, $company, $actor, $connection, 'ozon'));
        } finally {
            $redis->close();
        }
    }

    /** @param list<array<string,mixed>> $inputs */
    private function race(array $inputs): void
    {
        $barrier = self::getContainer()->getParameter('kernel.project_dir').'/var/preview-barrier-'.Uuid::uuid7()->toString();
        $processes = [];
        try {
            foreach ($inputs as $input) {
                $process = new Process([\PHP_BINARY, 'tests/Fixtures/MoySklad/automatic-preview-worker.php'], self::getContainer()->getParameter('kernel.project_dir'), input: json_encode($input + ['barrier' => $barrier], \JSON_THROW_ON_ERROR), timeout: 20);
                $process->start();
                $processes[] = $process;
                self::assertTrue($process->waitUntil(static fn (string $type, string $buffer): bool => str_contains($buffer, "ready\n")), $process->getErrorOutput());
            }
            touch($barrier);
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                self::assertMatchesRegularExpression('/success|rejected:preview_busy/', $process->getOutput());
            }
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }
}
