<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Application\StartBankAuthorizationAction;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Exception\UnknownBankCodeException;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Начало входа в банк: новое подключение (`bank_code`, `base_url`) или переподключение
 * (`connection_id`). Редирект на страницу входа банка.
 */
#[IsGranted('ROLE_USER')]
final class OAuthStartController extends AbstractController
{
    public const CSRF_ID = 'bank_sync_oauth_start';

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly StartBankAuthorizationAction $startAuthorization,
    ) {
    }

    #[Route('/bank-connections/oauth/start', name: 'bank_sync_oauth_start', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $connectionId = $request->request->getString('connection_id');
        if ('' !== $connectionId && !preg_match('/^'.Requirement::UUID.'$/', $connectionId)) {
            throw $this->createNotFoundException();
        }
        $bankCode = BankCode::tryFrom($request->request->getString('bank_code', BankCode::ALFA->value));
        $baseUrl = $request->request->getString('base_url', BankConnectionFormData::PRODUCTION_URL);
        if (null === $bankCode || !\in_array($baseUrl, [BankConnectionFormData::SANDBOX_URL, BankConnectionFormData::PRODUCTION_URL], true)) {
            throw $this->createNotFoundException();
        }

        try {
            $url = ($this->startAuthorization)(
                $companyId,
                $bankCode,
                $baseUrl,
                '' === $connectionId ? null : $connectionId,
                $this->generateUrl('bank_sync_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (BankConnectionNotFoundException) {
            throw $this->createNotFoundException();
        } catch (UnknownBankCodeException|BankAuthorizationNotConfiguredException) {
            $this->addFlash('warning', sprintf('Вход через %s пока не подключён.', $bankCode->label()));

            return $this->redirectToRoute('bank_sync_connection_index');
        }

        return $this->redirect($url);
    }
}
