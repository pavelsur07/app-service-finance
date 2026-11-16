<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class AutomaticMappingGuard
{
    public const RULE_VERSION = 'identity_package_v2';

    public function __construct(public string $previewId, public string $ruleVersion, public string $sourceFingerprint, public ?string $sizeCharacteristicId, public string $candidateFingerprint)
    {
        Assert::regex($candidateFingerprint, '/^[a-f0-9]{64}$/D');
        Assert::uuid($previewId);
        Assert::same($ruleVersion, self::RULE_VERSION);
        Assert::regex($sourceFingerprint, '/^[a-f0-9]{64}$/D');
        if (null !== $sizeCharacteristicId) {
            Assert::minLength($sizeCharacteristicId, 1);
            Assert::maxLength($sizeCharacteristicId, 255);
        }
    }

    /** @return array<string,mixed> */
    public function toSnapshot(): array
    {
        return ['origin' => 'automatic', 'previewId' => $this->previewId, 'ruleVersion' => $this->ruleVersion,
            'sourceFingerprint' => $this->sourceFingerprint, 'sizeCharacteristicId' => $this->sizeCharacteristicId, 'candidateFingerprint' => $this->candidateFingerprint];
    }
}
