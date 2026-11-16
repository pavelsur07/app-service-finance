<?php

declare(strict_types=1);

namespace App\MoySklad\Message;

use Webmozart\Assert\Assert;

final readonly class DiagnoseMappingsMessage
{
    public function __construct(public string $companyId, public string $connectionId, public string $marketplace, public string $runId, public ?string $sizeCharacteristicId = null)
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($runId);
        Assert::inArray($marketplace, ['ozon', 'wildberries']);
        if (null !== $sizeCharacteristicId) {
            Assert::minLength($sizeCharacteristicId, 1);
            Assert::maxLength($sizeCharacteristicId, 255);
        }
    }
}
