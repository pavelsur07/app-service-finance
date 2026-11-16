<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Form\ApplyAutomaticMappingsType;
use App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage;
use App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class ShowAutomaticMappingsPreviewController extends AbstractController
{
    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/automatic/{previewId}', name: 'moysklad_automatic_mapping_show', requirements: ['connectionId' => Requirement::UUID, 'previewId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['GET'])]
    public function __invoke(string $connectionId, string $marketplace, string $previewId, Request $request, ActiveCompanyService $activeCompany,
        MoySkladConnectionWriteRepository $connections, AutomaticMappingPreviewStorage $storage, MoySkladListingMappingsQuery $mappings, CsrfTokenManagerInterface $csrf): Response
    {
        $connectionId = strtolower($connectionId);
        $previewId = strtolower($previewId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $actor = $this->getUser();
        $actorId = $actor instanceof User ? $actor->getId() : null;
        if (null === $actorId) {
            throw $this->createAccessDeniedException();
        }
        $session = $request->getSession();
        $session->start();
        $attempt = $request->query->all()['attempt'] ?? null;
        if (null !== $attempt && (!is_string($attempt) || !Uuid::isValid($attempt))) {
            throw new UnprocessableEntityHttpException('Неверная попытка применения.');
        }
        try {
            $preview = $storage->get($session->getId(), $previewId, $companyId, $actorId, $connectionId, $marketplace);
            $result = null === $preview || null === $attempt ? null : $storage->getResult($session->getId(), $previewId, $attempt, $companyId, $actorId, $connectionId, $marketplace);
        } catch (ListingMappingException $error) {
            throw new HttpException($error->getStatusCode(), $error->getMessage());
        }
        if (null !== $attempt && null === $result) {
            $preview = null;
        }
        $form = null;
        $states = [];
        $labels = [];
        if (null !== $preview) {
            $states = $mappings->currentForListings($companyId, $connectionId, array_column($preview->items, 'listingId'));
            $ready = [];
            foreach ($preview->items as $item) {
                $labels[$item->listingId] = $item->source->title ?? $item->source->sku;
                if (AutomaticMappingDecisionStatus::READY === $item->decision->status) {
                    $ready[$item->listingId] = $item->source->sku;
                }
            }
            if ([] !== $ready && $this->isGranted(ModuleAccess::MARKETPLACE_WRITE)) {
                $form = $this->createForm(ApplyAutomaticMappingsType::class, ['selectedIds' => array_keys($ready), '_token' => $csrf->getToken(ApplyAutomaticMappingsType::csrfId($previewId))->getValue()],
                    ['listings' => $ready, 'action' => $this->generateUrl('moysklad_automatic_mapping_apply', ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'previewId' => $previewId])]);
            }
        }

        return $this->render('moy_sklad/mappings/automatic_preview.html.twig', ['connection' => $connection, 'mappingConnectionId' => $connectionId,
            'mappingMarketplace' => $marketplace, 'providerName' => MarketplaceType::from($marketplace)->getDisplayName(), 'preview' => $preview,
            'result' => $result, 'listingLabels' => $labels, 'states' => $states, 'applyForm' => $form?->createView()], new Response(status: null === $preview ? 404 : 200));
    }
}
