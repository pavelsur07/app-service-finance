<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Controller\Admin;

use App\Company\Entity\User;
use App\MarketplaceOzon\Application\Action\ConfirmOzonSourceTypeAction;
use App\MarketplaceOzon\Exception\OzonSourceTypeNotConfirmableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ConfirmOzonSourceTypeController extends AbstractController
{
    public function __construct(
        private readonly ConfirmOzonSourceTypeAction $confirm,
    ) {
    }

    #[Route('/admin/marketplace/ozon-source-types/confirm', name: 'admin_marketplace_ozon_source_types_confirm', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $sourceType = (string) $request->request->get('source_type', '');
        if (!$this->isCsrfTokenValid('moz_confirm_'.$sourceType, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF токен.');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            ($this->confirm)($sourceType, (string) $request->request->get('kind', ''), (string) $request->request->get('name', ''), (string) $user->getId());
            $this->addFlash('success', sprintf('Код %s подтверждён. Дни из карантина переведёт следующий прогон (до 15 минут).', $sourceType));
        } catch (OzonSourceTypeNotConfirmableException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_marketplace_ozon_source_types');
    }
}
