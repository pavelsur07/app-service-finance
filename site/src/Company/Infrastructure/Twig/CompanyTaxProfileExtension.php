<?php

declare(strict_types=1);

namespace App\Company\Infrastructure\Twig;

use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Facade\CompanyTaxProfileFacadeInterface;
use App\Company\Facade\DTO\CompanyTaxProfileDTO;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CompanyTaxProfileExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CompanyTaxProfileFacadeInterface $taxProfiles,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('active_company_tax_profile', $this->activeCompanyTaxProfile(...)),
            new TwigFunction('tax_vat_matrix', $this->taxVatMatrix(...)),
        ];
    }

    /**
     * Текущий налоговый профиль активной компании для шапок отчётов.
     *
     * @return array{profile: ?CompanyTaxProfileDTO, companyId: string, canEdit: bool}
     */
    public function activeCompanyTaxProfile(): array
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        return [
            'profile' => $this->taxProfiles->taxProfileOn($companyId, $this->clock->now()),
            'companyId' => $companyId,
            // Налоговые настройки меняет только владелец — как и карточку компании.
            'canEdit' => $company->getUser() === $this->security->getUser(),
        ];
    }

    /**
     * Допустимые режимы НДС по режиму налогообложения — для фильтра вариантов в форме.
     *
     * @return array<string, list<string>>
     */
    public function taxVatMatrix(): array
    {
        $matrix = [];
        foreach (TaxRegime::cases() as $regime) {
            $matrix[$regime->value] = array_map(static fn (VatMode $mode): string => $mode->value, $regime->allowedVatModes());
        }

        return $matrix;
    }
}
