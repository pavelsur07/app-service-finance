<?php

namespace App\Twig;

use App\Service\ActiveCompanyService;
use App\Service\FeatureFlagService;
use App\Service\MoneyTotalsWidgetProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class MoneyTotalsExtension extends AbstractExtension
{
    public function __construct(
        private readonly Environment $twig,
        private readonly FeatureFlagService $featureFlagService,
        private readonly MoneyTotalsWidgetProvider $widgetProvider,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('money_totals_widget', [$this, 'renderWidget'], ['is_safe' => ['html']]),
            new TwigFunction('is_funds_feature_enabled', [$this, 'isFeatureEnabled']),
        ];
    }

    public function renderWidget(): string
    {
        if (!$this->featureFlagService->isFundsAndWidgetEnabled()) {
            return '';
        }

        if (null === $this->security->getUser()) {
            return '';
        }

        try {
            try {
                $company = $this->activeCompanyService->getActiveCompany();
            } catch (NotFoundHttpException) {
                return '';
            }

            $data = $this->widgetProvider->build($company);

            return $this->twig->render('_partials/money_totals_widget.html.twig', $data);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to render money totals widget.', [
                'exception' => $exception,
            ]);

            return '';
        }
    }

    public function isFeatureEnabled(): bool
    {
        return $this->featureFlagService->isFundsAndWidgetEnabled();
    }
}
