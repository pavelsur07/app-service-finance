<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplacePlProjection;

use App\MarketplaceOperations\Enum\CascadeLevel;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplacePlProjection\Domain\MappingTemplate;
use PHPUnit\Framework\TestCase;

final class MappingTemplateTest extends TestCase
{
    public function testEveryKindHasADecisionAndInfoKindsAreExcluded(): void
    {
        $byKind = [];
        foreach (MappingTemplate::rules() as $rule) {
            if (null !== $rule->kind && null === $rule->channel) {
                $byKind[$rule->kind->value] = $rule->plCode;
            }
        }

        foreach (OperationKind::cases() as $kind) {
            self::assertArrayHasKey($kind->value, $byKind, 'вид без решения в шаблоне: '.$kind->value);
            self::assertSame(CascadeLevel::INFO === $kind->level(), null === $byKind[$kind->value], $kind->value);
        }
    }

    public function testCodesExistInStandardTreeAndKeysAreUnique(): void
    {
        $tree = (string) file_get_contents(__DIR__.'/../../../config/finance/pl_templates/marketplace.json');
        $keys = [];
        foreach (MappingTemplate::rules() as $rule) {
            $keys[] = $rule->key();
            if (null !== $rule->plCode) {
                self::assertStringContainsString('"'.$rule->plCode.'"', $tree);
            }
            self::assertSame(null === $rule->kind, null !== $rule->sourceType, 'ключ — вид либо код источника');
            self::assertTrue(null === $rule->sourceType || null !== $rule->channel, 'код источника — только с каналом');
        }

        self::assertSame($keys, array_values(array_unique($keys)));
    }
}
