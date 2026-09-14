<?php

declare(strict_types=1);

namespace App\Balance\Infrastructure\Query;

use App\Balance\Domain\Policy\LedgerAmount;
use App\Balance\DTO\BalanceRowView;
use App\Balance\ReadModel\BalanceReport;
use App\Shared\Domain\ValueObject\Money;

final readonly class BalanceReportQuery
{
    public function __construct(private LedgerQuery $ledger)
    {
    }

    public function buildForCompanyAndDate(string $companyId, \DateTimeImmutable $date): BalanceReport
    {
        $snapshot = $this->ledger->balance($companyId, $date->format('Y-m-d'));
        $currency = null === $snapshot['book'] ? null : $snapshot['book']['currency'];
        $currency = is_string($currency) ? $currency : null;
        $currencies = null === $currency ? [] : [$currency];
        /** @var array<string, string> $own */
        $own = [];
        foreach ($snapshot['accounts'] as $account) {
            $id = (string) $account['article_id'];
            $own[$id] = bcadd($own[$id] ?? '0', (string) $account['closing'], 0);
        }
        /** @var array<string, list<array<string, mixed>>> $children */
        $children = [];
        foreach ($snapshot['articles'] as $article) {
            $children[(string) ($article['parent_id'] ?? '')][] = $article;
        }
        $build = function (string $parent) use (&$build, $children, $own, $currency): array {
            $result = [];
            foreach ($children[$parent] ?? [] as $article) {
                $id = (string) $article['id'];
                $subrows = $build($id);
                $minor = $own[$id] ?? '0';
                foreach ($subrows as $subrow) {
                    $minor = bcadd($minor, $subrow['minor'], 0);
                }
                LedgerAmount::assertRange($minor);
                $amounts = null === $currency ? [] : [$currency => Money::fromMinor((int) $minor, $currency)->toDecimalString()];
                $result[] = ['minor' => $minor, 'view' => new BalanceRowView($id, (string) $article['name'], (string) $article['type'], (int) $article['level'], (int) $article['sort_order'], (bool) $article['is_visible'], $amounts, array_column($subrows, 'view'), $minor)];
            }

            return $result;
        };
        $roots = array_column($build(''), 'view');
        $amount = static function (string $minor) use ($currency): array {
            LedgerAmount::assertRange($minor);

            return null === $currency ? [] : [$currency => Money::fromMinor((int) $minor, $currency)->toDecimalString()];
        };

        return new BalanceReport($date, $currencies, $roots, $amount((string) $snapshot['asset']), $amount((string) $snapshot['passive']), $amount((string) $snapshot['difference']), (bool) $snapshot['initialized']);
    }
}
