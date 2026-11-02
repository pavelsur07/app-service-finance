<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Twig;

use App\Billing\Domain\ExpiryNotice;
use App\Billing\Facade\BillingFacadeInterface;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `billing_notice()` — предупреждение об окончании оплаты активного кабинета для баннера в layout.
 * Нет пользователя или активного кабинета (вход, выбор компании, админка) — null.
 */
final class BillingNoticeExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BillingFacadeInterface $billing,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('billing_notice', $this->notice(...))];
    }

    public function notice(): ?ExpiryNotice
    {
        if (null === $this->security->getUser()) {
            return null;
        }

        try {
            $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        } catch (NotFoundHttpException|SessionNotFoundException) {
            return null;
        }

        return ExpiryNotice::forAccess($this->billing->accessFor($companyId, $this->clock->now()));
    }
}
