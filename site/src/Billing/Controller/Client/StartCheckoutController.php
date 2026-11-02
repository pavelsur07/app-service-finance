<?php

declare(strict_types=1);

namespace App\Billing\Controller\Client;

use App\Billing\Application\DTO\StartCheckoutCommand;
use App\Billing\Application\StartCheckoutAction;
use App\Billing\Enum\TariffCode;
use App\Billing\Exception\OnlinePaymentNotAvailableException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Оплатить онлайн» на странице «Тариф и оплата»: владелец активного кабинета уходит на
 * страницу оплаты ЮKassa. Работает и в режиме «только чтение» — оплата его снимает.
 *
 * POST /company/price/checkout
 */
#[Route('/company/price/checkout', name: 'company_price_checkout', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class StartCheckoutController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly StartCheckoutAction $startCheckout,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $owner = $company->getUser();
        if (null === $owner || $owner !== $this->getUser()) {
            throw $this->createAccessDeniedException('Оплатить тариф может только владелец кабинета.');
        }
        if (!$this->isCsrfTokenValid('billing_checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела — обновите страницу и попробуйте ещё раз.');

            return $this->redirectToRoute('company_price_index');
        }

        $rawCheckoutId = (string) $request->request->get('checkout_id');
        $tariffCode = TariffCode::tryFrom((string) $request->request->get('tariff'));
        $months = $request->request->getInt('months');
        // Каноническая запись UUID (без скобок и urn:): ID уходит в metadata и Idempotence-Key ЮKassa
        // и сверяется с ID в БД строкой.
        $checkoutId = 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower($rawCheckoutId)) ? strtolower($rawCheckoutId) : null;
        if (null === $checkoutId || null === $tariffCode || $months < 1) {
            $this->addFlash('danger', 'Выберите тариф и срок.');

            return $this->redirectToRoute('company_price_index');
        }

        try {
            $confirmationUrl = ($this->startCheckout)(new StartCheckoutCommand(
                checkoutId: $checkoutId,
                companyId: (string) $company->getId(),
                tariffCode: $tariffCode,
                months: $months,
                returnUrl: $this->generateUrl('company_price_index', ['checkout' => $checkoutId], UrlGeneratorInterface::ABSOLUTE_URL),
                customerEmail: $owner->getEmail(),
            ));
        } catch (OnlinePaymentNotAvailableException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('company_price_index');
        }

        return new RedirectResponse($confirmationUrl, Response::HTTP_SEE_OTHER);
    }
}
