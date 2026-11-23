<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Controller\Admin;

use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceWb\Domain\WbSourceTypeSeed;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Коды WB, которые ждут решения администратора платформы (`DATA.md` §9.4), с черновиком ИИ.
 */
#[IsGranted('ROLE_ADMIN')]
final class WbSourceTypesController extends AbstractController
{
    public function __construct(
        private readonly WbTranslationStore $store,
    ) {
    }

    #[Route('/admin/marketplace/wb-source-types', name: 'admin_marketplace_wb_source_types', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/marketplace_wb/source_types.html.twig', [
            'pending' => $this->store->pendingForReview(),
            'kinds' => OperationKind::cases(),
            'suggested' => WbSourceTypeSeed::SUGGESTED,
        ]);
    }
}
