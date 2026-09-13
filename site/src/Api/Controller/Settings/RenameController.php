<?php

declare(strict_types=1);

namespace App\Api\Controller\Settings;

use App\Api\Application\RenameApiKeyAction;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Form\ApiKeyNameType;
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
final class RenameController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly ApiOwnerGuard $owner, private readonly RenameApiKeyAction $action, private readonly ApiKeyRepository $keys)
    {
    }

    #[Route('/settings/api/{id}/rename', name: 'settings_api_rename', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $id): Response
    {
        $company = $this->activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $userId = (string) $user->getId();
        $this->owner->assertOwner($companyId, $userId);
        $key = $this->keys->findOneByIdAndCompany($id, $companyId) ?? throw new ApiKeyNotFoundException();
        $form = $this->createForm(ApiKeyNameType::class, ['name' => $key->getName()], ['csrf_token_id' => 'api_key_rename_'.$companyId.'_'.$id]);
        $form->handleRequest($request);
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->submit([]);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            ($this->action)($companyId, $userId, $id, (string) $form->get('name')->getData());

            return $this->redirectToRoute('settings_api_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('api/settings/form.html.twig', ['activeCompany' => $company, 'form' => $form->createView(), 'title' => 'Переименовать API-ключ', 'submit' => 'Сохранить', 'warning' => 'Переименование не изменяет срок действия и права ключа.', 'key' => $key], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
