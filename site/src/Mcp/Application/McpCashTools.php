<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Cash\Application\DTO\PaymentIntake\CreateMcpCashDraft;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\ExternalCashReferenceUnavailableException;
use App\Cash\Exception\McpCashDraftException;
use App\Cash\Exception\PaymentIntakeAccessDeniedException;
use App\Cash\Facade\CashFacade;
use App\Mcp\Security\McpPrincipal;
use App\Mcp\Security\McpScope;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @phpstan-type ToolDefinition array{
 *     name: string, description: string,
 *     inputSchema: array<string, mixed>, outputSchema: array<string, mixed>, annotations: array<string, bool>
 * }
 * @phpstan-type ReferenceArguments array{kind: 'money_accounts'|'cashflow_categories', search?: string, limit: int|float, cursor?: string}
 * @phpstan-type TransactionArguments array{
 *     dateFrom: string, dateTo: string, moneyAccountId?: string, categoryId?: string,
 *     direction?: 'INFLOW'|'OUTFLOW', limit: int|float, cursor?: string
 * }
 * @phpstan-type DraftArguments array{
 *     externalId: string, moneyAccountId: string, date: string, direction: 'INFLOW'|'OUTFLOW',
 *     amount: string, currency?: string, categoryId?: string, description?: string
 * }
 */
final class McpCashTools
{
    private const string REFERENCES = 'list_reference_items';
    private const string TRANSACTIONS = 'list_cash_transactions';
    private const string DRAFT = 'create_cash_draft';
    private const array REFERENCE_SCOPES = [
        'money_accounts' => McpScope::AccountsRead->value,
        'cashflow_categories' => McpScope::CashCategoriesRead->value,
    ];
    private const array TOOL_SCOPES = [
        self::REFERENCES => self::REFERENCE_SCOPES,
        self::TRANSACTIONS => [McpScope::CashTransactionsRead->value],
        self::DRAFT => [McpScope::CashDraftsCreate->value],
    ];

    /** @var array<string, ToolDefinition> */
    private array $definitions = [];

    public function __construct(
        private readonly CashFacade $cash,
        private readonly Validator $validator,
        private readonly McpCashCursor $cursor,
    ) {
    }

    /** @return list<ToolDefinition> */
    public function definitions(McpPrincipal $principal): array
    {
        $tools = [];
        foreach (self::TOOL_SCOPES as $name => $scopes) {
            if ([] !== array_intersect($principal->scopes, $scopes)) {
                $tools[] = $this->definition($name);
            }
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function call(McpPrincipal $principal, string $name, array $arguments): array
    {
        $scopes = self::TOOL_SCOPES[$name] ?? throw new \InvalidArgumentException('Unknown MCP tool.');
        $kind = $arguments['kind'] ?? null;
        if (self::REFERENCES === $name && \is_string($kind) && isset(self::REFERENCE_SCOPES[$kind])) {
            $scopes = [self::REFERENCE_SCOPES[$kind]];
        }
        if ([] === array_intersect($principal->scopes, $scopes)) {
            throw new AccessDeniedHttpException();
        }
        $input = $this->validatedInput($name, $arguments);

        if (self::REFERENCES === $name) {
            /** @var ReferenceArguments $arguments */
            $arguments = $input;

            return $this->listReferences($principal, $arguments);
        }
        if (self::TRANSACTIONS === $name) {
            /** @var TransactionArguments $arguments */
            $arguments = $input;

            return $this->listTransactions($principal, $arguments);
        }

        /** @var DraftArguments $arguments */
        $arguments = $input;

        return $this->createDraft($principal, $arguments);
    }

    /**
     * @param ReferenceArguments $arguments
     *
     * @return array<string, mixed>
     */
    private function listReferences(McpPrincipal $principal, array $arguments): array
    {
        $kind = $arguments['kind'];
        $search = $arguments['search'] ?? null;
        $filters = ['kind' => $kind, 'search' => $search];
        $after = isset($arguments['cursor']) ? $this->cursor->decode($principal, self::REFERENCES, $filters, $arguments['cursor']) : null;
        /** @var int<1, 100> $limit */
        $limit = (int) $arguments['limit'];
        $items = match ($kind) {
            'money_accounts' => $this->cash->listExternalAccounts($principal->companyId, $search, $after['id'] ?? null, $limit + 1),
            'cashflow_categories' => $this->cash->listExternalCategories($principal->companyId, $search, $after['id'] ?? null, $limit + 1),
        };

        return ['kind' => $kind] + $this->page($principal, self::REFERENCES, $filters, $items, $limit);
    }

    /**
     * @param TransactionArguments $arguments
     *
     * @return array<string, mixed>
     */
    private function listTransactions(McpPrincipal $principal, array $arguments): array
    {
        $from = self::date($arguments['dateFrom']);
        $to = self::date($arguments['dateTo']);
        if ($from > $to || $from->diff($to)->days > 365) {
            throw McpToolException::invalidInput();
        }
        $account = isset($arguments['moneyAccountId']) ? strtolower($arguments['moneyAccountId']) : null;
        $category = isset($arguments['categoryId']) ? strtolower($arguments['categoryId']) : null;
        $direction = isset($arguments['direction']) ? CashDirection::from($arguments['direction']) : null;
        $filters = [
            'dateFrom' => $arguments['dateFrom'],
            'dateTo' => $arguments['dateTo'],
            'moneyAccountId' => $account,
            'categoryId' => $category,
            'direction' => $direction?->value,
        ];
        $after = isset($arguments['cursor']) ? $this->cursor->decode($principal, self::TRANSACTIONS, $filters, $arguments['cursor']) : null;
        if (null !== $after && !isset($after['date'])) {
            throw McpToolException::invalidInput();
        }
        /** @var int<1, 100> $limit */
        $limit = (int) $arguments['limit'];

        try {
            $items = $this->cash->listExternalTransactions($principal->companyId, $from, $to, $account, $category, $direction, $after, $limit + 1);
        } catch (ExternalCashReferenceUnavailableException $exception) {
            throw new McpToolException('reference_unavailable', $exception->getMessage());
        }

        return $this->page($principal, self::TRANSACTIONS, $filters, $items, $limit);
    }

    /**
     * @param DraftArguments $arguments
     *
     * @return array{draftId: string, source: 'mcp', status: string}
     */
    private function createDraft(McpPrincipal $principal, array $arguments): array
    {
        $command = new CreateMcpCashDraft(
            companyId: $principal->companyId,
            actorUserId: $principal->userId,
            connectionId: $principal->connectionId,
            externalId: $arguments['externalId'],
            moneyAccountId: strtolower($arguments['moneyAccountId']),
            date: self::date($arguments['date']),
            direction: CashDirection::from($arguments['direction']),
            amount: $arguments['amount'],
            currency: $arguments['currency'] ?? null,
            categoryId: isset($arguments['categoryId']) ? strtolower($arguments['categoryId']) : null,
            description: $arguments['description'] ?? '',
        );

        try {
            $result = $this->cash->createMcpCashDraft($command);
        } catch (McpCashDraftException $exception) {
            throw new McpToolException($exception->reason, $exception->getMessage());
        } catch (PaymentIntakeAccessDeniedException $exception) {
            throw new AccessDeniedHttpException(previous: $exception);
        }

        return ['draftId' => $result->draftId, 'source' => 'mcp', 'status' => $result->status];
    }

    /**
     * @template T of array{id: string, date?: string}
     *
     * @param array<string, string|null> $filters
     * @param list<T> $items
     * @param positive-int $limit
     *
     * @return array{items: list<T>, nextCursor: ?string}
     */
    private function page(McpPrincipal $principal, string $name, array $filters, array $items, int $limit): array
    {
        $nextCursor = null;
        if (\count($items) > $limit) {
            $items = \array_slice($items, 0, $limit);
            $last = $items[$limit - 1];
            $position = ['id' => $last['id']];
            if (isset($last['date'])) {
                $position['date'] = $last['date'];
            }
            $nextCursor = $this->cursor->encode($principal, $name, $filters, $position);
        }

        return ['items' => $items, 'nextCursor' => $nextCursor];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function validatedInput(string $name, array $arguments): array
    {
        foreach ($arguments as $value) {
            if (\is_string($value) && (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
                throw McpToolException::invalidInput();
            }
        }
        // Opis applies schema defaults to the input object during validation.
        $input = (object) $arguments;
        if (!$this->validator->validate($input, Helper::toJSON($this->definition($name)['inputSchema']))->isValid()) {
            throw McpToolException::invalidInput();
        }
        foreach (['moneyAccountId', 'categoryId'] as $field) {
            // Opis accepts a newline at the end of its UUID regex. PostgreSQL expects the full UUID.
            if (isset($arguments[$field]) && (!\is_string($arguments[$field]) || !Uuid::isValid($arguments[$field]))) {
                throw McpToolException::invalidInput();
            }
        }

        return (array) $input;
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw McpToolException::invalidInput();
        }

        return $date;
    }

    /** @return ToolDefinition */
    private function definition(string $name): array
    {
        if (!isset($this->definitions[$name])) {
            $json = file_get_contents(__DIR__.'/../../../contracts/mcp/v1/'.$name.'.json');
            if (false === $json) {
                throw new \LogicException('Missing MCP tool definition.');
            }
            /** @var ToolDefinition $definition */
            $definition = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            $this->definitions[$name] = $definition;
        }

        return $this->definitions[$name];
    }
}
