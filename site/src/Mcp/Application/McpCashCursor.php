<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Mcp\Security\McpPrincipal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class McpCashCursor
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    /**
     * @param array<string, string|null> $filters
     * @param array{id: string, date?: string} $position
     */
    public function encode(McpPrincipal $principal, string $tool, array $filters, array $position): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($position, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.$this->signature($principal, $tool, $filters, $payload);
    }

    /**
     * @param array<string, string|null> $filters
     *
     * @return array{id: string, date?: string}
     */
    public function decode(McpPrincipal $principal, string $tool, array $filters, string $cursor): array
    {
        $parts = explode('.', $cursor, 2);
        if (2 !== \count($parts) || !hash_equals($this->signature($principal, $tool, $filters, $parts[0]), $parts[1])) {
            throw McpToolException::invalidInput();
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $position = false === $json ? null : json_decode($json, true);
        if (!\is_array($position) || !\is_string($position['id'] ?? null)) {
            throw McpToolException::invalidInput();
        }

        return \is_string($position['date'] ?? null)
            ? ['id' => $position['id'], 'date' => $position['date']]
            : ['id' => $position['id']];
    }

    /** @param array<string, string|null> $filters */
    private function signature(McpPrincipal $principal, string $tool, array $filters, string $payload): string
    {
        ksort($filters, \SORT_STRING);

        return hash_hmac('sha256', json_encode([
            'mcp-cash-cursor-v1', $principal->companyId, $principal->connectionId, $tool, $filters, $payload,
        ], \JSON_THROW_ON_ERROR), $this->secret);
    }
}
