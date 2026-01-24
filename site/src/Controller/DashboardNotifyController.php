<?php

namespace App\Controller;

use App\Notification\DTO\EmailMessage;
use App\Notification\DTO\NotificationContext;
use App\Notification\Service\NotificationRouter;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardNotifyController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private NotificationRouter $notifier,
    ) {
    }

    #[Route(path: '/dashboard/notify', name: 'dashboard_notify', methods: ['POST'])]
    public function notify(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        // CSRF
        $this->isCsrfTokenValid('dashboard_notify', $request->request->get('_token'))
            || throw $this->createAccessDeniedException('CSRF token invalid.');

        try {
            $company = $this->activeCompanyService->getActiveCompany();
        } catch (NotFoundHttpException) {
            $this->addFlash('danger', 'Не выбрана активная компания.');

            return $this->redirectToRoute('app_home_index');
        }

        // email получателя: минимально — текущий пользователь
        $user = $this->getUser();
        $toEmail = method_exists($user, 'getEmail') ? (string) $user->getEmail() : '';

        if (!$toEmail) {
            $this->addFlash('danger', 'Не удалось определить email получателя.');

            return $this->redirectToRoute('app_home_index');
        }

        $vars = [
            'subject' => 'ФинПлан — аккаунт создан',
            'company_name' => method_exists($company, 'getName') ? $company->getName() : 'Компания',
            'company_id' => method_exists($company, 'getId') ? (string) $company->getId() : '—',
            'company_slug' => method_exists($company, 'getSlug') ? $company->getSlug() : null,
        ];

        $message = new EmailMessage(
            to: $toEmail,
            subject: $vars['subject'],
            htmlTemplate: 'notifications/email/account_opened.html.twig',
            textTemplate: 'notifications/email/account_opened.txt.twig',
            vars: $vars,
        );

        $ctx = new NotificationContext(
            companyId: $vars['company_id'],
            locale: 'ru',
        );

        $ok = $this->notifier->send('email', $message, $ctx);

        $this->addFlash($ok ? 'success' : 'danger', $ok
            ? 'Уведомление отправлено на ваш email.'
            : 'Не удалось отправить уведомление.'
        );

        return $this->redirectToRoute('app_home_index');
    }
}
