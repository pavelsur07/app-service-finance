<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * `.env` лежит в git и читается Symfony во ВСЕХ окружениях, поэтому заполненное
 * здесь значение достаётся каждому разработчику без единого действия с его стороны.
 *
 * Так и вышло с `SENTRY_DSN`: файл нёс боевой DSN проекта GlitchTip, и dev-контейнеры
 * писали в продовый проект. Подтверждение — issue 320 в `app_vashfindirru`: наша
 * миграция `uniq_ingest_order_status_event_observation` с `environment=dev`.
 *
 * Прод от `.env` не зависит: `SENTRY_DSN` приходит из GitHub secrets через `x-php-env`
 * в `docker-compose.prod.yml`, а переменная окружения перекрывает `.env`. Значит
 * заполнять его здесь незачем — только вредно.
 */
final class CommittedEnvTest extends TestCase
{
    public function testCommittedEnvDoesNotShipASentryDsn(): void
    {
        $value = $this->envValue('SENTRY_DSN');

        self::assertNotNull($value, 'Ключ SENTRY_DSN исчез из .env: подстановка окружения перестанет быть явной.');
        self::assertSame('', $value, implode(' ', [
            'В коммитируемом .env задан SENTRY_DSN.',
            'Symfony читает .env во всех окружениях, поэтому dev-контейнеры начнут писать в продовый GlitchTip.',
            'Боевое значение подставляется окружением (GitHub secrets → docker-compose.prod.yml), здесь оно должно быть пустым.',
        ]));
    }

    /**
     * Тот же класс дефекта шире одного ключа: DSN-образное значение вида
     * https://<ключ>@<хост>/<project id> — это пишущая учётка трекера, и в git ей не место.
     */
    public function testCommittedEnvShipsNoTrackerDsnUnderAnyKey(): void
    {
        $offenders = [];
        foreach ($this->envPairs() as $key => $value) {
            if (1 === preg_match('#^https?://[^@/\s]+@[^\s]+/\d+$#', $value)) {
                $offenders[] = $key;
            }
        }

        self::assertSame([], $offenders, sprintf(
            'В коммитируемом .env есть DSN-образные значения: %s. Их место в окружении, а не в git.',
            implode(', ', $offenders),
        ));
    }

    private function envValue(string $key): ?string
    {
        return $this->envPairs()[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function envPairs(): array
    {
        $path = \dirname(__DIR__, 3).'/.env';
        self::assertFileExists($path);

        $pairs = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (1 !== preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $matches)) {
                continue;
            }

            $pairs[$matches[1]] = trim($matches[2], "\"'");
        }

        return $pairs;
    }
}
