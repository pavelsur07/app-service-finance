<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Entity;

use App\Marketplace\Entity\MarketplaceListingTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarketplaceListingTagSlugTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-4111-8111-111111111111';

    #[DataProvider('sameTagSpellings')]
    public function testSpellingVariantsCollapseToSameSlug(string $name): void
    {
        $tag = $this->tag($name);

        self::assertSame('зима', $tag->getSlug());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sameTagSpellings(): iterable
    {
        yield 'as typed' => ['Зима'];
        yield 'padded' => ['  зима  '];
        yield 'upper case' => ['ЗИМА'];
        yield 'mixed casing' => ['зИмА'];
    }

    public function testKeepsOriginalNameTrimmedButNotLowercased(): void
    {
        $tag = $this->tag('  Зимняя Коллекция  ');

        self::assertSame('Зимняя Коллекция', $tag->getName());
        self::assertSame('зимняя коллекция', $tag->getSlug());
    }

    public function testRenameRecalculatesNameAndSlug(): void
    {
        $tag = $this->tag('Зма');

        $tag->rename('  Зимняя Коллекция  ');

        self::assertSame('Зимняя Коллекция', $tag->getName());
        self::assertSame('зимняя коллекция', $tag->getSlug());
    }

    public function testRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tag('   ');
    }

    public function testRenameRejectsBlankName(): void
    {
        $tag = $this->tag('Зима');

        $this->expectException(\InvalidArgumentException::class);

        $tag->rename('   ');
    }

    public function testRejectsTooLongName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tag(str_repeat('я', MarketplaceListingTag::NAME_MAX_LENGTH + 1));
    }

    private function tag(string $name): MarketplaceListingTag
    {
        return new MarketplaceListingTag(
            '22222222-2222-4222-8222-222222222222',
            self::COMPANY_ID,
            $name,
        );
    }
}
