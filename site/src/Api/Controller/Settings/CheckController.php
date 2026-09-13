<?php

declare(strict_types=1);

namespace App\Api\Controller\Settings;

use App\Api\Application\AuthenticateApiKeyAction;
use App\Api\Application\RecordApiKeyUseAction;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Form\ApiKeyCheckType;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CheckController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly ApiOwnerGuard $owner, private readonly AuthenticateApiKeyAction $authenticate, private readonly RecordApiKeyUseAction $recordUse)
    {
    }

    #[Route('/settings/api/check', name: 'settings_api_check', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $company = $this->activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $userId = (string) $user->getId();
        $this->owner->assertOwner($companyId, $userId);
        $form = $this->createForm(ApiKeyCheckType::class, null, ['csrf_token_id' => 'api_key_check_'.$companyId]);
        $form->handleRequest($request);
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->submit([]);
        }
        $status = $form->isSubmitted() ? 422 : 200;
        $message = null;
        $details = null;
        $headers = [];
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $principal = ($this->authenticate)((string) $form->get('secret')->getData(), (string) $company->getPublicId(), $request->getClientIp() ?? 'unknown');
                ($this->recordUse)($principal->companyId, $principal->keyId);
                $details = $principal->connectionDetails();
                $message = 'Подключение работает. Ключ действителен для этой компании.';
                $status = 200;
            } catch (HttpExceptionInterface $exception) {
                $status = $exception->getStatusCode();
                $headers = $exception->getHeaders();
                $message = match ($status) {
                    401 => 'Ключ недействителен, отозван или срок его действия истёк.',
                    403 => 'Ключ не принадлежит активной компании.',
                    422 => 'Не удалось определить публичный ID компании.',
                    429 => 'Слишком много проверок. Повторите попытку позже.',
                    default => throw $exception,
                };
            }
        }

        return $this->render('api/settings/check.html.twig', ['activeCompany' => $company, 'form' => $form->createView(), 'message' => $message, 'details' => $details], new Response(status: $status, headers: $headers));
    }
}
