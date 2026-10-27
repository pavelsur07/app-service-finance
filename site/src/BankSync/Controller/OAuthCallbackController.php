<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\CompleteBankAuthorizationAction;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationDeniedException;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankAuthorizationStateInvalidException;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Exception\BankIdTokenInvalidException;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Возврат клиента из банка. Любой исход — redirect на список подключений с сообщением;
 * код и токены в ответ и в сообщения не попадают.
 */
#[IsGranted('ROLE_USER')]
final class OAuthCallbackController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CompleteBankAuthorizationAction $completeAuthorization,
    ) {
    }

    #[Route('/bank-connections/oauth/callback', name: 'bank_sync_oauth_callback', methods: ['GET'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $error = $request->query->getString('error');

        try {
            ($this->completeAuthorization)(
                $companyId,
                $request->query->getString('state'),
                $request->query->getString('code'),
                '' === $error ? null : $error,
            );
            $this->addFlash('success', 'Банк подключён.');
        } catch (BankAuthorizationStateInvalidException $exception) {
            $this->addFlash('danger', match ($exception->reason) {
                'company_mismatch' => 'Вход в банк начат в другой компании. Переключитесь на неё или начните вход заново.',
                'connection_changed' => 'Настройки подключения изменились, пока шёл вход в банк. Начните вход заново.',
                default => 'Сеанс входа в банк устарел. Начните подключение заново.',
            });
        } catch (BankAuthorizationDeniedException) {
            $this->addFlash('warning', 'Банк не выдал доступ: вход отменён или истёк. Начните подключение заново.');
        } catch (BankAuthorizationRejectedException) {
            $this->addFlash('danger', 'Банк отклонил вход. Начните подключение заново.');
        } catch (BankApiUnavailableException) {
            $this->addFlash('danger', 'Банк временно недоступен. Попробуйте подключиться позже.');
        } catch (BankApiRequestFailedException|BankAuthorizationNotConfiguredException|BankIdTokenInvalidException) {
            $this->addFlash('danger', 'Не удалось завершить вход в банк. Попробуйте позже или напишите в поддержку.');
        } catch (BankConnectionNotFoundException) {
            $this->addFlash('warning', 'Подключение удалили, пока шёл вход в банк.');
        } catch (BankConnectionBusyException) {
            $this->addFlash('warning', 'Подключение сейчас обновляется. Начните вход в банк заново через минуту.');
        }

        return $this->redirectToRoute('bank_sync_connection_index');
    }
}
