<?php

declare(strict_types=1);

namespace App\Billing\Controller\Admin;

use App\Billing\Application\DTO\RegisterPaymentCommand;
use App\Billing\Application\RegisterPaymentAction;
use App\Billing\Enum\PaymentSource;
use App\Billing\Exception\InvalidBillingInputException;
use App\Billing\Exception\PerpetualTariffChangeNotConfirmedException;
use App\Billing\Exception\TariffChangeNotSupportedException;
use App\Billing\Exception\TariffNotPayableException;
use App\Billing\Repository\BillingPaymentRepository;
use App\Company\Facade\CompanyFacade;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /admin/billing/companies/{companyId}/payments — отметить оплату по счёту.
 * `payment_id` — UUIDv7, выданный при показе формы: повторная отправка не создаёт вторую оплату.
 */
#[Route('/admin/billing/companies/{companyId}/payments', name: 'admin_billing_company_payment', requirements: ['companyId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class RegisterCompanyPaymentController extends AbstractController
{
    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly BillingPaymentRepository $payments,
        private readonly RegisterPaymentAction $registerPayment,
    ) {
    }

    public function __invoke(string $companyId, Request $request): RedirectResponse
    {
        $this->companies->findById($companyId) ?? throw $this->createNotFoundException('Кабинет не найден.');
        if (!$this->isCsrfTokenValid('billing_payment_'.$companyId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        try {
            $paymentId = $request->request->getString('payment_id');
            if (!Uuid::isValid($paymentId)) {
                throw new InvalidBillingInputException('Форма устарела — обновите страницу.');
            }

            $invoice = trim($request->request->getString('invoice'));
            $id = ($this->registerPayment)(new RegisterPaymentCommand(
                $paymentId,
                $companyId,
                AdminBillingForm::tariff($request),
                AdminBillingForm::months($request),
                AdminBillingForm::amountKopecks($request),
                AdminBillingForm::date($request, 'received_on', 'дату зачисления'),
                PaymentSource::MANUAL_INVOICE,
                '' === $invoice ? null : $invoice,
                $request->request->getBoolean('confirm_leave_perpetual'),
            ));

            $payment = $this->payments->findOneByIdAndCompanyId($id, $companyId);
            $this->addFlash('success', null === $payment
                ? 'Оплата зачислена.'
                : sprintf('Оплата зачислена: период с %s по %s.', $payment->getPeriodFrom()->format('d.m.Y'), $payment->getPeriodUntil()->format('d.m.Y')));
        } catch (InvalidBillingInputException|TariffNotPayableException|TariffChangeNotSupportedException|PerpetualTariffChangeNotConfirmedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_billing_company', ['companyId' => $companyId]);
    }
}
