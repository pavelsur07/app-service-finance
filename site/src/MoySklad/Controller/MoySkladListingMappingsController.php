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
    public function __invoke(string $connectionId, string $marketplace, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, MarketplaceFacade $listings, MoySkladListingMappingsQuery $mappings, MoySkladCatalogTargetsQuery $targets, MappingCharacteristicsQuery $characteristics, CsrfTokenManagerInterface $csrf, \App\MoySklad\Infrastructure\Query\MappingDiagnosticsQuery $diagnostics, \App\MoySklad\Infrastructure\Query\MappingSettingsQuery $settings): Response
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
        $diagPage = filter_var($values['diag_page'] ?? 1, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $diagStatus = $values['diag_status'] ?? '';
        $diagReason = $values['diag_reason'] ?? '';
        $view = $values['view'] ?? '';
        // Очередь без явного фильтра открывается на «Требует внимания»; пустой diag_manual в URL — «все строки отчёта».
        $diagManual = $values['diag_manual'] ?? ('all' !== $view && '' === $diagStatus && '' === $diagReason ? '1' : '');
        if (false === $diagPage || !is_string($diagStatus) || !is_string($diagReason) || !is_string($diagManual) || !in_array($view, ['', 'queue', 'all'], true)
            || ('' !== $diagStatus && null === \App\MoySklad\Enum\AutomaticMappingDecisionStatus::tryFrom($diagStatus))
            || ('' !== $diagReason && null === \App\MoySklad\Enum\MappingDiagnosticReason::tryFrom($diagReason)) || !in_array($diagManual, ['', '0', '1'], true)) {
            throw new UnprocessableEntityHttpException('Некорректный фильтр диагностики.');
        }
        $diagnosticPage = $diagnostics->page($companyId, $connectionId, $marketplace, $diagPage, '' === $diagStatus ? null : $diagStatus, '' === $diagReason ? null : $diagReason, '' === $diagManual ? null : '1' === $diagManual);
        $view = null !== $diagnosticPage->completedRun && ('queue' === $view || ('' === $view && '' === $q)) ? 'queue' : 'all';
        $rowIds = 'queue' === $view ? array_map(static fn (\App\MoySklad\Application\DTO\MappingDiagnosticRow $row): string => $row->listingId, $diagnosticPage->rows) : array_column($result->items, 'id');
        $states = $mappings->currentForListings($companyId, $connectionId, $rowIds);

        return $this->render('moy_sklad/mappings/index.html.twig', [
            'diagnostics' => $diagnosticPage, 'mappingCompanyId' => $companyId, 'mappingSettings' => $settings->get($companyId, $connectionId, $marketplace),
            'diagnosticCharacteristicChoices' => $choices,
            'diagnosticRequestId' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'diagnosticStatuses' => \App\MoySklad\Enum\AutomaticMappingDecisionStatus::cases(), 'diagnosticReasons' => \App\MoySklad\Enum\MappingDiagnosticReason::cases(),
            'view' => $view, 'diagStatus' => $diagStatus, 'diagReason' => $diagReason, 'diagManual' => $diagManual,
            'connection' => $connection, 'mappingConnectionId' => $connectionId, 'mappingMarketplace' => $marketplace,
            'providerName' => MarketplaceType::from($marketplace)->getDisplayName(),
            'previewForm' => $previewForm->createView(), 'characteristicsPage' => $charResult, 'charQ' => $charQ, 'selectedCharacteristicId' => $selected,
            'listingsPage' => $result, 'states' => $states, 'q' => $q, 'catalogReady' => $targets->isReady($companyId, $connectionId),
        ]);
    }
}
