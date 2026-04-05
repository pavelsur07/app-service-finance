<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Domain;

use App\Marketplace\Domain\Service\ResolveMarketplaceRawProcessingProfile;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStep;
use PHPUnit\Framework\TestCase;

final class ResolveMarketplaceRawProcessingProfileTest extends TestCase
{
    private ResolveMarketplaceRawProcessingProfile $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ResolveMarketplaceRawProcessingProfile();
    }

    public function testSalesReportIsDailyPipeline(): void
    {
        $profile = $this->resolver->resolve(MarketplaceType::WILDBERRIES, 'sales_report');

        self::assertTrue($profile->isDailyPipeline);
        self::assertNull($profile->skipReason);
        self::assertContains(PipelineStep::SALES, $profile->requiredSteps);
        self::assertContains(PipelineStep::RETURNS, $profile->requiredSteps);
        self::assertContains(PipelineStep::COSTS, $profile->requiredSteps);
        self::assertCount(3, $profile->requiredSteps);
    }

    public function testSalesReportRequiresAllThreeSteps(): void
    {
        $profile = $this->resolver->resolve(MarketplaceType::OZON, 'sales_report');

        self::assertTrue($profile->requiresStep(PipelineStep::SALES));
        self::assertTrue($profile->requiresStep(PipelineStep::RETURNS));
        self::assertTrue($profile->requiresStep(PipelineStep::COSTS));
    }

    public function testSalesReportWorksForAllMarketplaces(): void
    {
        foreach (MarketplaceType::cases() as $marketplace) {
            $profile = $this->resolver->resolve($marketplace, 'sales_report');
            self::assertTrue($profile->isDailyPipeline, "Expected daily pipeline for {$marketplace->value}");
        }
    }

    public function testRealizationIsOutsideDailyFlow(): void
    {
        $profile = $this->resolver->resolve(MarketplaceType::OZON, 'realization');

        self::assertFalse($profile->isDailyPipeline);
        self::assertEmpty($profile->requiredSteps);
        self::assertNotNull($profile->skipReason);
        self::assertStringContainsString('realization', $profile->skipReason);
    }

    public function testRealizationDoesNotRequireAnyStep(): void
    {
        $profile = $this->resolver->resolve(MarketplaceType::OZON, 'realization');

        self::assertFalse($profile->requiresStep(PipelineStep::SALES));
        self::assertFalse($profile->requiresStep(PipelineStep::RETURNS));
        self::assertFalse($profile->requiresStep(PipelineStep::COSTS));
    }

    public function testUnknownDocumentTypeIsOutsideDailyFlow(): void
    {
        $profile = $this->resolver->resolve(MarketplaceType::WILDBERRIES, 'some_other_report');

        self::assertFalse($profile->isDailyPipeline);
        self::assertEmpty($profile->requiredSteps);
        self::assertNotNull($profile->skipReason);
    }

    public function testUnknownDocumentTypeSkipReasonMentionsDocumentType(): void
    {
        $profile = $this->resolver->resolve(MarketplaceType::WILDBERRIES, 'mystery_doc');

        self::assertStringContainsString('mystery_doc', $profile->skipReason);
    }
}
