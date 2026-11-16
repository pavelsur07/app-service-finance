<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplacePlProjection;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplacePlProjection\Domain\Rule;
use App\MarketplacePlProjection\Domain\RuleResolver;
use App\MarketplacePlProjection\Enum\RuleOrigin;
use PHPUnit\Framework\TestCase;

final class RuleResolverTest extends TestCase
{
    public function testSourceTypeBeatsKindAndChannelBeatsKind(): void
    {
        $rules = [
            $this->rule('by-kind', OperationKind::FINE),
            $this->rule('by-channel', OperationKind::FINE, 'wildberries'),
            $this->rule('by-code', null, 'wildberries', 'wb:field:penalty'),
        ];

        self::assertSame('by-code', $this->resolve($rules, OperationKind::FINE, 'wildberries', 'wb:field:penalty'));
        self::assertSame('by-channel', $this->resolve($rules, OperationKind::FINE, 'wildberries', 'wb:deduction:x'));
        self::assertSame('by-kind', $this->resolve($rules, OperationKind::FINE, 'ozon', 'ozon:service:1'));
    }

    public function testRuleOfAnotherChannelOrKindDoesNotApply(): void
    {
        $rules = [
            $this->rule('wb', OperationKind::FINE, 'wildberries'),
            $this->rule('code', null, 'wildberries', 'same-code'),
        ];

        self::assertNull($this->resolve($rules, OperationKind::FINE, 'ozon', 'same-code'));
        self::assertNull($this->resolve($rules, OperationKind::STORAGE, 'wildberries', 'other'));
        self::assertNull($this->resolve([], OperationKind::SALE, 'ozon', 'x'));
    }

    public function testLatestRuleEffectiveOnTheDayWins(): void
    {
        $rules = [
            $this->rule('old', OperationKind::STORAGE),
            $this->rule('new', OperationKind::STORAGE, validFrom: '2026-11-01'),
            $this->rule('future', OperationKind::STORAGE, validFrom: '2026-12-01'),
        ];

        self::assertSame('old', $this->resolve($rules, OperationKind::STORAGE, 'ozon', 'x', '2026-10-31'));
        self::assertSame('new', $this->resolve($rules, OperationKind::STORAGE, 'ozon', 'x', '2026-11-01'));
        self::assertSame('new', $this->resolve($rules, OperationKind::STORAGE, 'ozon', 'x', '2026-11-30'));
    }

    public function testMoreSpecificOlderRuleBeatsNewerGeneralOne(): void
    {
        $rules = [
            $this->rule('code', null, 'ozon', 'ozon:service:7'),
            $this->rule('kind-new', OperationKind::MP_OTHER_DEDUCTIONS, validFrom: '2026-11-01'),
        ];

        self::assertSame('code', $this->resolve($rules, OperationKind::MP_OTHER_DEDUCTIONS, 'ozon', 'ozon:service:7', '2026-11-10'));
    }

    public function testExcludedRuleIsReturnedAsRuleWithoutCategory(): void
    {
        $rule = RuleResolver::resolve([new Rule(OperationKind::SPP, null, null, null, RuleOrigin::PRESET, Rule::BASE_DATE)], OperationKind::SPP, 'ozon', 'x', '2026-11-10');

        self::assertNotNull($rule);
        self::assertNull($rule->plCategoryId);
    }

    /** @param list<Rule> $rules */
    private function resolve(array $rules, OperationKind $kind, string $channel, string $sourceType, string $day = '2026-11-10'): ?string
    {
        return RuleResolver::resolve($rules, $kind, $channel, $sourceType, $day)?->plCategoryId;
    }

    private function rule(string $category, ?OperationKind $kind, ?string $channel = null, ?string $sourceType = null, string $validFrom = Rule::BASE_DATE): Rule
    {
        return new Rule($kind, $channel, $sourceType, $category, RuleOrigin::CUSTOM, $validFrom);
    }
}
