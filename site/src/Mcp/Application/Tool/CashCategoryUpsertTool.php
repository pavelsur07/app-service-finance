<?php

declare(strict_types=1);

namespace App\Mcp\Application\Tool;

use App\Cash\Application\DTO\CashflowCategoryInput;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Facade\CashFacade;
use App\Mcp\Application\McpToolInterface;

final class CashCategoryUpsertTool implements McpToolInterface
{
    use JsonToolOutput;
    use EnumArgument;

    public function __construct(
        private readonly CashFacade $cashFacade,
    ) {
    }

    public function name(): string
    {
        return 'cash_category_upsert';
    }

    public function description(): string
    {
        return 'Создать статью ДДС (без id) или изменить существующую (с id). '
            .'Переданы будут только указанные поля, остальные останутся прежними. '
            .'Вложенность — не глубже 5 уровней. Системные статьи менять нельзя.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'UUID статьи. Без него создаётся новая.'],
                'name' => ['type' => 'string', 'description' => 'Название. Обязательно при создании.'],
                'parentId' => ['type' => 'string', 'description' => 'UUID родительской статьи'],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['active', 'disabled']],
                'sort' => ['type' => 'integer', 'description' => 'Порядок среди соседей'],
                'flowKind' => [
                    'type' => 'string',
                    'enum' => ['OPERATING', 'INVESTING', 'FINANCING', 'TECHNICAL'],
                    'description' => 'Вид деятельности. У дочерних статей наследуется от корня.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function call(string $companyId, array $arguments): string
    {
        $id = $this->cashFacade->upsertCashflowCategory($companyId, new CashflowCategoryInput(
            id: $this->stringArg($arguments, 'id'),
            name: $this->stringArg($arguments, 'name'),
            parentId: $this->stringArg($arguments, 'parentId'),
            description: $this->stringArg($arguments, 'description'),
            status: $this->enumArg($arguments, 'status', CashflowCategoryStatus::class),
            sort: isset($arguments['sort']) ? (int) $arguments['sort'] : null,
            flowKind: $this->enumArg($arguments, 'flowKind', CashflowFlowKind::class),
        ));

        return $this->json(['id' => $id, 'saved' => true]);
    }
}
