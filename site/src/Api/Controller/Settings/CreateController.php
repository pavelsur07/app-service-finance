<?php

declare(strict_types=1);

namespace App\Api\Controller\Settings;

use App\Api\Application\CreateApiKeyAction;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Form\ApiKeyNameType;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CreateController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly ApiOwnerGuard $owner, private readonly CreateApiKeyAction $create, private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('/settings/api/create', name: 'settings_api_create', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $company = $this->activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $userId = (string) $user->getId();
        $this->owner->assertOwner($companyId, $userId);
        $tokenId = 'api_key_create_'.$companyId;
        $form = $this->createForm(ApiKeyNameType::class, null, ['csrf_token_id' => $tokenId]);
        $form->handleRequest($request);
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->submit([]);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $created = ($this->create)($companyId, $userId, (string) $form->get('name')->getData());
            $this->csrf->removeToken($tokenId);

            return $this->render('api/settings/created.html.twig', ['activeCompany' => $company, 'key' => $created->key, 'secret' => $created->revealToken()], new Response(status: Response::HTTP_CREATED));
        }

        return $this->render('api/settings/form.html.twig', ['activeCompany' => $company, 'form' => $form->createView(), 'title' => 'Создать API-ключ', 'submit' => 'Создать ключ', 'warning' => 'Срок действия — 90 суток. Секрет будет показан только один раз.'], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
