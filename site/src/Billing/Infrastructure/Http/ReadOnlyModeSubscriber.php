<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Http;

use App\Billing\Domain\ReadOnlyRule;
use App\Billing\Facade\BillingFacadeInterface;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Гейт режима «только чтение» (ReadOnlyRule) для кабинета клиента — firewall `main`.
 * Статус берётся у компании, которую меняет запрос: из пути (`{companyId}`, `/company/{id}/…`),
 * если у пользователя есть к ней доступ, иначе — активной из сессии.
 * Флаг BILLING_READ_ONLY_ENFORCED выключен — запрос проходит, в журнал пишется «заблокировал бы».
 * HTML — redirect на «Тариф и оплата» с сообщением; JSON/XHR/`/api/` — 403 `billing_read_only`.
 */
final class ReadOnlyModeSubscriber implements EventSubscriberInterface
{
    public const ERROR_CODE = 'billing_read_only';
    public const MESSAGE = 'Кабинет в режиме «только чтение»: оплаченный период закончился. Продлите тариф, чтобы вносить изменения.';

    public function __construct(
        private readonly Security $security,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BillingFacadeInterface $billing,
        private readonly CompanyFacade $companies,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:BILLING_READ_ONLY_ENFORCED)%')]
        private readonly bool $enforced,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // После модульного гейта и #[IsGranted] (приоритет 0): нет доступа к модулю — 403 от них,
        // а не redirect на тариф; иначе замена контроллера на closure их бы обошла.
        return [KernelEvents::CONTROLLER => ['onKernelController', -8]];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !ReadOnlyRule::isMutating($request->getMethod())) {
            return;
        }
        $route = $request->attributes->get('_route');
        $route = \is_string($route) ? $route : null;
        if (ReadOnlyRule::isAllowedRoute($route)
            || 'main' !== $this->security->getFirewallConfig($request)?->getName()
            || null === $this->security->getUser()) {
            return;
        }

        $companyId = $this->targetCompanyId($request, $route);
        if (null === $companyId) {
            return;
        }

        $status = $this->billing->accessFor($companyId, $this->clock->now())->status;
        if (!ReadOnlyRule::blocks($request->getMethod(), $route, $status)) {
            return;
        }

        $context = ['companyId' => $companyId, 'route' => $route, 'method' => $request->getMethod()];
        if (!$this->enforced) {
            $this->logger->info('Billing: только чтение — запрос заблокировал бы (режим выключен)', $context);

            return;
        }

        $this->logger->info('Billing: только чтение — изменяющий запрос отклонён', $context);
        $response = $this->response($request);
        $event->setController(static fn (): Response => $response);
    }

    private function targetCompanyId(Request $request, ?string $route): ?string
    {
        $routeParams = $request->attributes->get('_route_params');
        $target = ReadOnlyRule::targetCompanyId($route, \is_array($routeParams) ? $routeParams : []);
        $user = $this->security->getUser();
        // Чужую компанию контроллер и так отклонит; её статус не используем, чтобы не раскрывать его.
        if (null !== $target && $user instanceof User && $this->companies->userHasAccess($target, (string) $user->getId())) {
            return $target;
        }

        try {
            return (string) $this->activeCompanyService->getActiveCompany()->getId();
        } catch (NotFoundHttpException|SessionNotFoundException) {
            return null;
        }
    }

    private function response(Request $request): Response
    {
        if ($this->wantsJson($request)) {
            // `message` верхнего уровня — для legacy-скриптов, читающих data.message.
            return new JsonResponse(['error' => ['code' => self::ERROR_CODE, 'message' => self::MESSAGE], 'message' => self::MESSAGE], Response::HTTP_FORBIDDEN);
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', self::MESSAGE);
        }

        return new RedirectResponse($this->urls->generate('company_price_index'), Response::HTTP_SEE_OTHER);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_starts_with($request->getPathInfo(), '/api/')
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
            || 'json' === $request->getContentTypeFormat();
    }
}
