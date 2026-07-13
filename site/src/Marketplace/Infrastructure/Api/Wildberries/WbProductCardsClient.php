<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WbProductCardsClient
{
    private const LIST_ENDPOINT = 'https://content-api.wildberries.ru/content/v2/get/cards/list';
    private const TRASH_ENDPOINT = 'https://content-api.wildberries.ru/content/v2/get/cards/trash';
    private const PAGE_SIZE = 100;

    public function __construct(
        private HttpClientInterface $httpClient,
        private RateLimiterFactory $rateLimiter,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $apiKey): array
    {
        return $this->fetchAllFrom($apiKey, self::LIST_ENDPOINT, 'updatedAt', true);
    }

    /** @return list<array<string, mixed>> */
    public function fetchAllTrash(string $apiKey): array
    {
        return $this->fetchAllFrom($apiKey, self::TRASH_ENDPOINT, 'trashedAt', false);
    }

    /** @return list<array<string, mixed>> */
    private function fetchAllFrom(string $apiKey, string $endpoint, string $cursorDateField, bool $withPhotoFilter): array
    {
        $cards = [];
        $cursor = ['limit' => self::PAGE_SIZE];
        $seenCursors = [];
        $lastCursor = null;
        $limiter = $this->rateLimiter->create(hash('sha256', $apiKey));

        while (true) {
            $limiter->reserve()->wait();
            $page = $this->fetchPage($apiKey, $endpoint, $cursor, $withPhotoFilter);
            $cards = [...$cards, ...$page['cards']];

            if (count($page['cards']) < self::PAGE_SIZE) {
                break;
            }

            $nextCursor = $this->nextCursor($page['cursor'], $cursorDateField);
            $cursorKey = $nextCursor[$cursorDateField].':'.$nextCursor['nmID'];
            if (isset($seenCursors[$cursorKey]) || !$this->cursorAdvances($lastCursor, $nextCursor, $cursorDateField)) {
                throw $this->invalidResponse('WB Product Cards cursor must advance.');
            }

            $seenCursors[$cursorKey] = true;
            $lastCursor = $nextCursor;
            $cursor = ['limit' => self::PAGE_SIZE, ...$nextCursor];
        }

        return $cards;
    }

    /**
     * @param array<string, int|string> $cursor
     *
     * @return array{cards: list<array<string, mixed>>, cursor: array<string, mixed>}
     */
    private function fetchPage(string $apiKey, string $endpoint, array $cursor, bool $withPhotoFilter): array
    {
        $settings = [
            'sort' => ['ascending' => true],
            'cursor' => $cursor,
        ];
        if ($withPhotoFilter) {
            $settings['filter'] = ['withPhoto' => -1];
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => ['Authorization' => $apiKey],
                'json' => ['settings' => $settings],
                'timeout' => 120,
            ]);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new MarketplaceTemporaryApiException('WB Product Cards transport error.', 0, '', '', '', $e);
        }

        $excerpt = mb_substr(trim($body), 0, 500);
        if (401 === $statusCode || 403 === $statusCode) {
            throw new MarketplaceAuthException('WB Product Cards authentication failed.', $statusCode, $excerpt, '', '');
        }
        if (400 === $statusCode) {
            throw new MarketplaceBadRequestException('WB Product Cards rejected request payload.', $statusCode, $excerpt, '', '');
        }
        if (429 === $statusCode) {
            throw new MarketplaceRateLimitException($statusCode, $excerpt, '', '', $this->retryAfter($headers));
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            throw new MarketplaceTemporaryApiException('WB Product Cards temporary error.', $statusCode, $excerpt, '', '');
        }
        if (200 !== $statusCode) {
            throw new MarketplaceTemporaryApiException('WB Product Cards unexpected status.', $statusCode, $excerpt, '', '');
        }

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MarketplaceInvalidApiResponseException('WB Product Cards returned invalid JSON.', $statusCode, $excerpt, '', '', $e);
        }

        if (!is_array($decoded) || !isset($decoded['cards'], $decoded['cursor']) || !is_array($decoded['cards']) || !array_is_list($decoded['cards']) || !is_array($decoded['cursor'])) {
            throw $this->invalidResponse('WB Product Cards response must contain cards list and cursor object.', $statusCode, $excerpt);
        }

        foreach ($decoded['cards'] as $card) {
            if (!is_array($card)) {
                throw $this->invalidResponse('WB Product Cards list items must be objects.', $statusCode, $excerpt);
            }
        }

        return ['cards' => $decoded['cards'], 'cursor' => $decoded['cursor']];
    }

    /**
     * @param array<string, mixed> $cursor
     *
     * @return array<string, int|string>
     */
    private function nextCursor(array $cursor, string $cursorDateField): array
    {
        $cursorDate = trim((string) ($cursor[$cursorDateField] ?? ''));
        $nmId = filter_var($cursor['nmID'] ?? null, \FILTER_VALIDATE_INT);

        if ('' === $cursorDate || false === $nmId || $nmId <= 0) {
            throw $this->invalidResponse('WB Product Cards pagination cursor is invalid.');
        }

        return [$cursorDateField => $cursorDate, 'nmID' => $nmId];
    }

    /**
     * @param array<string, int|string>|null $previous
     * @param array<string, int|string>      $next
     */
    private function cursorAdvances(?array $previous, array $next, string $cursorDateField): bool
    {
        if (null === $previous) {
            return true;
        }

        $dateComparison = strcmp((string) $next[$cursorDateField], (string) $previous[$cursorDateField]);

        return $dateComparison > 0 || (0 === $dateComparison && $next['nmID'] > $previous['nmID']);
    }

    /** @param array<string, list<string>> $headers */
    private function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? null;

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    private function invalidResponse(string $message, int $statusCode = 200, string $excerpt = ''): MarketplaceInvalidApiResponseException
    {
        return new MarketplaceInvalidApiResponseException($message, $statusCode, $excerpt, '', '');
    }
}
