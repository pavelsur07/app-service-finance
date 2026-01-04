<?php

namespace App\Twig;

use App\Cash\Service\Accounts\AccountMasker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class MaskingExtension extends AbstractExtension
{
    public function __construct(private readonly AccountMasker $accountMasker)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('mask_account', [$this, 'maskAccount']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mask_account', [$this, 'maskAccount']),
        ];
    }

    public function maskAccount(mixed $accountNumber): ?string
    {
        return $this->accountMasker->mask($accountNumber);
    }
}
