<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Controller\Admin;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Коды Ozon, которые ждут решения администратора платформы (`DATA.md` §9.4), с черновиком ИИ.
 */
#[IsGranted('ROLE_ADMIN')]
final class OzonSourceTypesController extends AbstractController
{
    public function __construct(
        private readonly OzonTranslationStore $store,
    ) {
    }

    #[Route('/admin/marketplace/ozon-source-types', name: 'admin_marketplace_ozon_source_types', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/marketplace_ozon/source_types.html.twig', [
            'pending' => $this->store->pendingForReview(),
            'kinds' => OperationKind::cases(),
        ]);
    }
}
