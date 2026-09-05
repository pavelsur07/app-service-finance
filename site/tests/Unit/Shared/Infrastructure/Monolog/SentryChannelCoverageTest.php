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
