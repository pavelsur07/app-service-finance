<?php

declare(strict_types=1);

namespace App\Api\Controller\Settings;

use App\Api\Application\RevokeApiKeyAction;
use App\Api\Application\Service\ApiOwnerGuard;
use App\Api\Exception\ApiKeyNotFoundException;
use App\Api\Repository\ApiKeyRepository;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class RevokeController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly ApiOwnerGuard $owner, private readonly RevokeApiKeyAction $action, private readonly ApiKeyRepository $keys, private readonly FormFactoryInterface $forms)
    {
    }

    #[Route('/settings/api/{id}/revoke', name: 'settings_api_revoke', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $id): Response
    {
        $company = $this->activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $userId = (string) $user->getId();
        $this->owner->assertOwner($companyId, $userId);
        $key = $this->keys->findOneByIdAndCompany($id, $companyId) ?? throw new ApiKeyNotFoundException();
        $form = $this->forms->createNamed('api_key_revoke', FormType::class, null, ['csrf_token_id' => 'api_key_revoke_'.$companyId.'_'.$id]);
        $form->handleRequest($request);
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->submit([]);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            ($this->action)($companyId, $userId, $id);

            return $this->redirectToRoute('settings_api_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('api/settings/form.html.twig', ['activeCompany' => $company, 'form' => $form->createView(), 'title' => 'Отозвать API-ключ', 'submit' => 'Отозвать ключ', 'warning' => 'Отзыв необратим. Подключения с этим ключом перестанут работать.', 'key' => $key], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
