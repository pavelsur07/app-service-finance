<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use Symfony\Component\Clock\ClockInterface;

/**
 * База для ресурсов, которые обходятся чаще раза в сутки.
 *
 * Отличается от {@see AbstractDailyCursorIncrementalStrategy} не только
 * частотой: суточный курсор — дата `Y-m-d`, и её формат сам по себе не даёт
 * опрашивать ресурс дважды за день. Часовому курсору нужна отметка времени, и
 * интервал приходится проверять явно.
 *
 * Каденция принадлежит ресурсу, а не расписанию крона: `run-incremental` может
 * запускаться сколь угодно часто, а `cursorIsDue()` решает, пора ли.
 */
abstract readonly class AbstractHourlyCursorIncrementalStrategy implements IncrementalResourceStrategyInterface
{
    /**
     * Порог намеренно МЕНЬШЕ периода крона.
     *
     * Курсор пишется по фактическому времени работы воркера, а не по времени
     * запуска крона: между диспатчем сообщения и его обработкой проходит
     * очередь. Пусть крон стоит на :35, а прошлый pull отработал в 12:36 —
     * при пороге ровно в 60 минут запуск в 13:35 даст «ещё не пора», и
     * ресурс молча станет двухчасовым. Каждая следующая минута задержки
     * сдвигала бы окно дальше, и деградация накапливалась бы.
     *
     * Пять минут запаса покрывают обычную задержку очереди. Обратной
     * опасности нет: повторный обход того же окна идемпотентен, дедуп raw по
     * sha256 и апсерт заказа не создают дублей.
     */
    private const DEFAULT_MIN_INTERVAL_MINUTES = 55;

    public function __construct(
        private ClockInterface $clock,
        private int $minIntervalMinutes = self::DEFAULT_MIN_INTERVAL_MINUTES,
    ) {
    }

    public function cursorIsDue(string $cursorValue): bool
    {
        $cursorInstant = $this->normalizedCursorInstant($cursorValue);
        if (null === $cursorInstant) {
            // Нечитаемый курсор считаем просроченным: лишний запрос дешевле
            // навсегда застывшего ресурса.
            return true;
        }

        return $cursorInstant <= $this->clock->now()->modify(sprintf('-%d minutes', $this->minIntervalMinutes));
    }

    private function normalizedCursorInstant(string $cursorValue): ?\DateTimeImmutable
    {
        $value = trim($cursorValue);
        if ('' === $value) {
            return null;
        }

        try {
            $payload = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
            if (is_array($payload) && is_string($payload['since'] ?? null)) {
                $value = $payload['since'];
            }
        } catch (\JsonException) {
            // Не JSON — пробуем как голую отметку времени.
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
