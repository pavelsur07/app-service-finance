<?php

declare(strict_types=1);

namespace App\Marketplace\Domain\ValueObject;

use App\Marketplace\Enum\PipelineStep;
use Webmozart\Assert\Assert;

/**
 * Профиль обработки raw-документа маркетплейса.
 *
 * Определяет, участвует ли документ в daily raw pipeline (sales/returns/costs)
 * и какие шаги являются обязательными для данного профиля.
 *
 * Документы вне daily flow (realization, прочие) получают профиль
 * с isDailyPipeline=false и пустым списком шагов.
 */
final readonly class MarketplaceRawProcessingProfile
{
    /**
     * @param PipelineStep[] $requiredSteps
     */
    private function __construct(
        public bool $isDailyPipeline,
        public array $requiredSteps,
        public ?string $skipReason,
    ) {}

    /**
     * Профиль для документов, участвующих в daily raw pipeline.
     *
     * @param PipelineStep[] $requiredSteps
     */
    public static function daily(array $requiredSteps): self
    {
        Assert::notEmpty($requiredSteps);
        Assert::allIsInstanceOf($requiredSteps, PipelineStep::class);

        return new self(
            isDailyPipeline: true,
            requiredSteps: $requiredSteps,
            skipReason: null,
        );
    }

    /**
     * Профиль для документов, не входящих в daily raw pipeline.
     * Шаги sales/returns/costs для таких документов не запускаются.
     */
    public static function outsideDailyFlow(string $reason): self
    {
        return new self(
            isDailyPipeline: false,
            requiredSteps: [],
            skipReason: $reason,
        );
    }

    public function requiresStep(PipelineStep $step): bool
    {
        return in_array($step, $this->requiredSteps, true);
    }
}
