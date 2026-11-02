<?php

declare(strict_types=1);

namespace App\Billing\Controller\Client;

use App\Billing\Message\ApplyYooKassaPaymentMessage;
use App\Company\Security\PublicAccess;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Уведомление ЮKassa о платеже (`payment.succeeded`, `payment.canceled`). Публичный адрес
 * без подписи: телу не доверяем — берём только ID платежа и ставим проверку в очередь,
 * `ApplyYooKassaPaymentAction` читает платёж из ЮKassa сам. Платёж, которого нет в
 * `billing_checkouts`, в ЮKassa не запрашивается — поддельные уведомления не вызывают HTTP.
 *
 * Отвечает 200 сразу: иначе ЮKassa повторяет уведомление до суток.
 *
 * POST /billing/yookassa/webhook
 */
#[AsController]
#[PublicAccess]
#[Route('/billing/yookassa/webhook', name: 'billing_yookassa_webhook', methods: ['POST'])]
final class YooKassaWebhookController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $event = \is_array($payload) ? ($payload['event'] ?? null) : null;
        $object = \is_array($payload) ? ($payload['object'] ?? null) : null;
        $paymentId = \is_array($object) ? ($object['id'] ?? null) : null;

        if (!\is_string($event) || !\is_string($paymentId) || 1 !== preg_match('/^[0-9a-f-]{36}$/', $paymentId)) {
            $this->logger->warning('Billing: уведомление ЮKassa не разобрано');

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        if (\in_array($event, ['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture'], true)) {
            $this->bus->dispatch(new ApplyYooKassaPaymentMessage($paymentId));
        }
        $this->logger->info('Billing: уведомление ЮKassa принято', ['event' => $event, 'providerPaymentId' => $paymentId]);

        return new Response('', Response::HTTP_OK);
    }
}
