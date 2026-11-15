<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Form\AutomaticMappingsPreviewType;
use App\MoySklad\Infrastructure\Query\MappingCharacteristicsQuery;
use App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery;
use App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class MoySkladListingMappingsController extends AbstractController
{
    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}', name: 'moysklad_listing_mappings', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['GET'])]
    public function __invoke(string $connectionId, string $marketplace, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, MarketplaceFacade $listings, MoySkladListingMappingsQuery $mappings, MoySkladCatalogTargetsQuery $targets, MappingCharacteristicsQuery $characteristics, CsrfTokenManagerInterface $csrf): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $raw = $request->query->all()['q'] ?? '';
        $q = is_string($raw) ? mb_substr(trim($raw), 0, 100) : '';
        $page = $request->query->getInt('page', 1);
        if ($page < 1) {
            throw new UnprocessableEntityHttpException('Неверный номер страницы.');
        }
        $result = $listings->paginateListingsForExternalMapping($companyId, $marketplace, $q, $page);
        if ($page > 1 && [] === $result->items && $result->totalItems > 0) {
            throw new UnprocessableEntityHttpException('Страница не существует.');
        }
        $values = $request->query->all();
        $charQ = $values['char_q'] ?? '';
        $selected = $values['sizeCharacteristicId'] ?? '';
        $charPage = filter_var($values['char_page'] ?? 1, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if (!is_string($charQ) || mb_strlen($charQ) > 100 || !is_string($selected) || mb_strlen($selected) > 255 || false === $charPage) {
            throw new UnprocessableEntityHttpException('Некорректный поиск характеристики.');
        }
        $charQ = trim($charQ);
        $selected = '' === $selected ? null : $selected;
        $charResult = $characteristics->page($companyId, $connectionId, $charQ, $charPage);
        $choices = $charResult->items;
        if (null !== $selected) {
            $current = $characteristics->find($companyId, $connectionId, $selected) ?? throw $this->createNotFoundException();
            if (!in_array($selected, array_column($choices, 'id'), true)) {
                $choices[] = $current;
            }
        }
        $previewForm = $this->createForm(AutomaticMappingsPreviewType::class,
            ['q' => $q, 'page' => (string) $page, 'sizeCharacteristicId' => $selected,
                '_token' => $csrf->getToken(AutomaticMappingsPreviewType::csrfId($connectionId, $marketplace, $charQ, $charPage, $selected))->getValue()],
            ['characteristics' => $choices, 'action' => $this->generateUrl('moysklad_automatic_mapping_preview',
                ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'char_q' => $charQ, 'char_page' => $charPage, 'selected' => $selected ?? ''])]);
        $states = $mappings->currentForListings($companyId, $connectionId, array_column($result->items, 'id'));

        return $this->render('moy_sklad/mappings/index.html.twig', [
            'connection' => $connection, 'mappingConnectionId' => $connectionId, 'mappingMarketplace' => $marketplace,
            'providerName' => MarketplaceType::from($marketplace)->getDisplayName(),
            'previewForm' => $previewForm->createView(), 'characteristicsPage' => $charResult, 'charQ' => $charQ, 'selectedCharacteristicId' => $selected,
            'listingsPage' => $result, 'states' => $states, 'q' => $q, 'catalogReady' => $targets->isReady($companyId, $connectionId),
        ]);
    }
}
