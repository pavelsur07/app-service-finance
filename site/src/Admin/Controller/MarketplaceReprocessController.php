<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Marketplace\Message\ReprocessCostsMessage;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/marketplace/reprocess', name: 'admin_marketplace_reprocess_')]
#[IsGranted('ROLE_ADMIN')]
final class MarketplaceReprocessController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(MarketplaceRawDocumentRepository $repository): Response
    {
        $documents = $repository->findDocsWithCrossCompanyCosts();

        return $this->render('admin/marketplace/reprocess/index.html.twig', [
            'documents' => array_map(static fn($doc): array => [
                'id' => $doc->getId(),
                'company_id' => (string) $doc->getCompany()->getId(),
            ], $documents),
        ]);
    }

    #[Route('/run', name: 'run', methods: ['POST'])]
    public function run(
        Request $request,
        MarketplaceRawDocumentRepository $repository,
        MessageBusInterface $bus,
    ): Response {
        $csrfToken = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('admin_marketplace_reprocess', $csrfToken)) {
            throw $this->createAccessDeniedException('Недействительный CSRF токен.');
        }

        $companyId = trim((string) $request->request->get('company_id', ''));
        $filterCompanyId = $companyId !== '' ? $companyId : null;

        $documents = $repository->findDocsWithCrossCompanyCosts($filterCompanyId);

        if ($documents === []) {
            $this->addFlash('success', 'Нет документов для переобработки.');

            return $this->redirectToRoute('admin_marketplace_reprocess_index');
        }

        foreach ($documents as $doc) {
            $bus->dispatch(new ReprocessCostsMessage(
                companyId: (string) $doc->getCompany()->getId(),
                rawDocumentId: $doc->getId(),
            ));
        }

        $this->addFlash('success', sprintf('Отправлено в очередь: %d документов.', count($documents)));

        return $this->redirectToRoute('admin_marketplace_reprocess_index');
    }
}
