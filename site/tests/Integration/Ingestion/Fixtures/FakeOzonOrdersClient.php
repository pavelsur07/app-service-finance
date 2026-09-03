<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;

final class FakeOzonOrdersClient implements OzonOrdersClientInterface
{
    /** @var (callable(string, IngestOrderScheme): void)|null */
    private $postingRequestHook;

    /**
     * Вызовы обоих эндпоинтов: у списка и у одиночного отправления разный
     * набор ключей, поэтому тип общий.
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * @var list<OzonRawPage|\Throwable>
     */
    private array $queued = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $postings = [];

    /**
     * Сбой на конкретном номере отправления: так проверяется, что уже
     * полученные ответы не выбрасываются вместе с ошибкой.
     *
     * @var array<string, \Throwable>
     */
    private array $postingFailures = [];

    public function queue(OzonRawPage|\Throwable ...$pages): void
    {
        $this->queued = array_values($pages);
    }

    /**
     * Что сделать в момент запроса отправления — до ответа.
     *
     * Нужно тестам гонок: за время сетевого вызова конкурент успевает
     * изменить заказ, и прогон обязан заметить это под блокировкой.
     *
     * @param callable(string, IngestOrderScheme): void $hook
     */
    public function onPostingRequest(callable $hook): void
    {
        $this->postingRequestHook = $hook;
    }

    /**
     * @param array<string, array<string, mixed>> $postings номер отправления => ответ
     */
    public function setPostings(array $postings): void
    {
        $this->postings = $postings;
    }

    /**
     * @param array<string, \Throwable> $failures номер => исключение
     */
    public function setPostingFailures(array $failures): void
    {
        $this->postingFailures = $failures;
    }

    public function fetchPosting(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        string $postingNumber,
    ): ?array {
        $this->calls[] = ['endpoint' => 'posting_get', 'postingNumber' => $postingNumber];

        if (null !== $this->postingRequestHook) {
            ($this->postingRequestHook)($postingNumber, $scheme);
        }

        if (isset($this->postingFailures[$postingNumber])) {
            throw $this->postingFailures[$postingNumber];
        }

        return $this->postings[$postingNumber] ?? null;
    }

    public function fetchPostings(
        string $companyId,
        string $connectionRef,
        IngestOrderScheme $scheme,
        \DateTimeImmutable $since,
        \DateTimeImmutable $to,
        int $limit,
        int $offset,
    ): OzonRawPage {
        $this->calls[] = [
            'scheme' => $scheme->value,
            'since' => $since->format(\DATE_ATOM),
            'to' => $to->format(\DATE_ATOM),
            'limit' => $limit,
            'offset' => $offset,
        ];

        $next = array_shift($this->queued) ?? new OzonRawPage([], false, null, []);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
