<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Monolog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Инвариант наблюдаемости: ни один объявленный канал Monolog не должен быть исключён
 * из sentry-хендлера.
 *
 * Канал `marketplace_ads` был исключён, и из-за этого 15 мест с `logger->error()` в
 * модуле MarketplaceAds не попадали в GlitchTip вообще. Падения
 * `app:marketplace-ads:scheduler` были видны только внутри общей корзины supercronic.
 * Объём при этом ограничивает не список каналов, а уровень хендлера (`error`) и
 * SentryRateLimiter, поэтому исключение канала не экономило шум — оно просто
 * выключало наблюдаемость целиком.
 */
final class SentryChannelCoverageTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function environmentProvider(): iterable
    {
        yield 'prod' => ['when@prod'];
        yield 'dev' => ['when@dev'];
    }

    #[DataProvider('environmentProvider')]
    public function testSentryHandlerRestrictsNoChannel(string $environmentKey): void
    {
        $config = $this->config();

        self::assertArrayHasKey($environmentKey, $config, sprintf('Секция %s исчезла из monolog.yaml.', $environmentKey));

        $handlers = $config[$environmentKey]['monolog']['handlers'] ?? [];
        self::assertIsArray($handlers);
        self::assertArrayHasKey('sentry', $handlers, sprintf('В %s нет sentry-хендлера.', $environmentKey));

        // Проверяется отсутствие любого ограничения, а не только исключений через "!":
        // положительный список вида `channels: [app]` так же тихо выключил бы все
        // остальные каналы, и именно так дефект и выглядел бы при следующей правке.
        $channels = $handlers['sentry']['channels'] ?? [];
        self::assertIsArray($channels);

        self::assertSame([], $channels, sprintf(
            'Sentry-хендлер в %s ограничен списком каналов %s: ошибки остальных каналов не попадут в GlitchTip. '
            .'Объём ограничивают уровень хендлера и SentryRateLimiter, а не список каналов.',
            $environmentKey,
            json_encode($channels, \JSON_UNESCAPED_UNICODE),
        ));
    }

    public function testSentryHandlerStaysErrorOnly(): void
    {
        $config = $this->config();

        foreach (['when@prod', 'when@dev'] as $environmentKey) {
            $handler = $config[$environmentKey]['monolog']['handlers']['sentry'] ?? null;
            self::assertIsArray($handler);
            self::assertSame(
                'error',
                $handler['level'] ?? null,
                sprintf('Уровень sentry-хендлера в %s должен остаться error: он и есть ограничитель объёма.', $environmentKey),
            );
        }
    }

    /**
     * Второй инвариант наблюдаемости: канал, исключённый из прод-`main`, обязан иметь
     * собственный handler.
     *
     * Исключение из `main` — единственный способ вывести INFO канала мимо
     * fingers_crossed, но оно же и отрезает канал от вывода целиком. Убрать handler,
     * оставив "!channel" в `main`, — значит молча потерять INFO и WARNING этого канала;
     * ERROR ещё будет виден через sentry, поэтому в GlitchTip такая потеря не заметна.
     */
    public function testChannelExcludedFromProdMainHasItsOwnHandler(): void
    {
        $handlers = $this->config()['when@prod']['monolog']['handlers'] ?? [];
        self::assertIsArray($handlers);

        $mainChannels = $handlers['main']['channels'] ?? [];
        self::assertIsArray($mainChannels);

        foreach ($mainChannels as $channel) {
            self::assertIsString($channel);
            if (!str_starts_with($channel, '!')) {
                continue;
            }

            $excluded = substr($channel, 1);
            $covered = false;

            foreach ($handlers as $name => $handler) {
                if ('main' === $name || !is_array($handler)) {
                    continue;
                }

                if (in_array($excluded, $handler['channels'] ?? [], true)) {
                    $covered = true;
                    break;
                }
            }

            self::assertTrue($covered, sprintf(
                'Канал %s исключён из прод-main, но своего handler-а не имеет: его INFO и WARNING '
                .'никуда не пишутся, и отказ этого канала снова становится невидимым.',
                $excluded,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $path = \dirname(__DIR__, 5).'/config/packages/monolog.yaml';
        self::assertFileExists($path);

        $parsed = Yaml::parseFile($path);
        self::assertIsArray($parsed);

        return $parsed;
    }
}
