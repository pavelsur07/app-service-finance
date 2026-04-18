<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdRawDocumentTest extends TestCase
{
    public function testMarkAsProcessedFromDraft(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->build();

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());

        $updatedAtBefore = $doc->getUpdatedAt();

        $doc->markAsProcessed();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());
        // updatedAt не должен сдвигаться назад; равенство допустимо, если вызовы
        // произошли в одну и ту же микросекунду (юнит-тест без клока).
        self::assertGreaterThanOrEqual($updatedAtBefore, $doc->getUpdatedAt());
    }

    public function testMarkAsProcessedFromProcessedThrows(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->asProcessed()->build();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('уже обработан');

        $doc->markAsProcessed();
    }

    public function testResetToDraft(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->asProcessed()->build();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());

        $doc->resetToDraft();

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());
    }

    public function testResetToDraftFromDraftThrows(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->build();

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('уже в статусе черновик');

        $doc->resetToDraft();
    }

    public function testUpdatePayloadResetsToDraft(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->asProcessed()->build();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());

        $newPayload = '{"rows":[{"sku":"NEW","spend":42}]}';
        $doc->updatePayload($newPayload);

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());
        self::assertSame($newPayload, $doc->getRawPayload());
    }

    public function testMarkFailedFromDraftSetsStatusAndReason(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->build();

        self::assertSame(AdRawDocumentStatus::DRAFT, $doc->getStatus());
        self::assertNull($doc->getProcessingError());

        $doc->markFailed('Парсинг упал: неизвестный формат');

        self::assertSame(AdRawDocumentStatus::FAILED, $doc->getStatus());
        self::assertSame('Парсинг упал: неизвестный формат', $doc->getProcessingError());
    }

    public function testMarkAsProcessedFromFailedIsAllowed(): void
    {
        $doc = AdRawDocumentBuilder::aRawDocument()->asFailed()->build();

        self::assertSame(AdRawDocumentStatus::FAILED, $doc->getStatus());

        $doc->markAsProcessed();

        self::assertSame(AdRawDocumentStatus::PROCESSED, $doc->getStatus());
    }

    #[DataProvider('terminalStatusProvider')]
    public function testMarkFailedFromTerminalThrows(AdRawDocumentStatus $terminal): void
    {
        $builder = AdRawDocumentBuilder::aRawDocument();
        $doc = match ($terminal) {
            AdRawDocumentStatus::PROCESSED => $builder->asProcessed()->build(),
            AdRawDocumentStatus::FAILED    => $builder->asFailed()->build(),
            default                        => throw new \LogicException('unexpected'),
        };

        $this->expectException(\DomainException::class);

        $doc->markFailed('повторная попытка');
    }

    /**
     * @return array<string, array{AdRawDocumentStatus}>
     */
    public static function terminalStatusProvider(): array
    {
        return [
            'already PROCESSED' => [AdRawDocumentStatus::PROCESSED],
            'already FAILED'    => [AdRawDocumentStatus::FAILED],
        ];
    }
}
