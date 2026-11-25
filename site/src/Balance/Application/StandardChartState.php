<?php

declare(strict_types=1);

namespace App\Balance\Application;

/** @internal Состояние одного прохода `SyncStandardChartAction`. */
final class StandardChartState
{
    public int $created = 0;
    /** @var list<string> */
    public array $skipped = [];

    /**
     * @param array<string, array{id: ?string, parent_id: ?string, kind: string, level: int|string, is_archived: bool|int|string, type?: string}> $articles
     * @param array<string, true> $accounts
     */
    public function __construct(public array $articles, public array $accounts)
    {
    }
}
