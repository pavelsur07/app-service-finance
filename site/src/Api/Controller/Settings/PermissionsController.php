<?php

declare(strict_types=1);

namespace App\Api\Controller\Settings;

use App\Api\Application\SaveApiKeyPermissionsAction;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Domain\ApiScopeCatalog;
use App\Api\Domain\ApiScopePolicy;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Form\ApiKeyPermissionsType;
use App\Api\Repository\ApiKeyRepository;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class PermissionsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ApiOwnerGuard $owner,
        private readonly ApiKeyRepository $keys,
        private readonly SaveApiKeyPermissionsAction $save,
    ) {
    }

    #[Route('/settings/api/{id}/permissions', name: 'settings_api_permissions', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $id): Response
    {
        $company = $this->activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $userId = (string) $user->getId();
        $this->owner->assertOwner($companyId, $userId);
        $key = $this->keys->findOneByIdAndCompany($id, $companyId) ?? throw new ApiKeyNotFoundException();
        $form = $this->createForm(ApiKeyPermissionsType::class, [
            'selectedScopes' => $key->getSelectedScopes(),
            'enabledResources' => $key->getEnabledResources(),
            'version' => (string) $key->getVersion(),
        ], ['csrf_token_id' => 'api_key_permissions_'.$companyId.'_'.$id]);
        $form->handleRequest($request);
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->submit([]);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            ($this->save)($companyId, $userId, $id, $form->get('selectedScopes')->getData(), $form->get('enabledResources')->getData(), (int) $form->get('version')->getData());
            $this->addFlash('api_permissions_success', 'Права сохранены.');

            return $this->redirectToRoute('settings_api_permissions', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('api/settings/permissions.html.twig', [
            'activeCompany' => $company,
            'key' => $key,
            'form' => $form->createView(),
            'resources' => ApiScopeCatalog::resources(),
            'presets' => ApiScopeCatalog::presets(),
            'effectiveScopes' => ApiScopePolicy::effectiveScopes($key->getSelectedScopes(), $key->getEnabledResources(), ApiScopeCatalog::connectedResources()),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
