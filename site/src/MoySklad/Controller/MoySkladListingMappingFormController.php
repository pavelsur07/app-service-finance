<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Marketplace\DTO\ListingMetaDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Application\DTO\ListingMappingState;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Enum\ListingMappingOperation;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Form\MoySkladListingMappingType;
use App\MoySklad\Infrastructure\Query\MoySkladCatalogTargetsQuery;
use App\MoySklad\Infrastructure\Query\MoySkladListingMappingsQuery;
use App\MoySklad\Infrastructure\Query\MoySkladSyncStatusQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class MoySkladListingMappingFormController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly MoySkladConnectionWriteRepository $connections,
        private readonly MarketplaceFacade $listings,
        private readonly MoySkladListingMappingsQuery $mappings,
        private readonly MoySkladCatalogTargetsQuery $targets,
        private readonly MoySkladSyncStatusQuery $syncStatus,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly MoySkladListingMappingRepository $revisions,
    ) {
    }

    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/{listingId}/edit', name: 'moysklad_listing_mapping_edit', requirements: ['connectionId' => Requirement::UUID, 'listingId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['GET', 'POST'])]
    public function edit(string $connectionId, string $marketplace, string $listingId, Request $request, ChangeListingMappingAction $action): Response
    {
        $connectionId = strtolower($connectionId);
        $listingId = strtolower($listingId);
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::MARKETPLACE_WRITE);
        }
        $context = $this->context($connectionId, $marketplace, $listingId);
        $csrfId = 'moysklad_listing_mapping'.$connectionId.$listingId;
        $values = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        if ($request->isMethod('POST')) {
            if (!is_string($values['_token'] ?? null) || !$this->isCsrfTokenValid($csrfId, $values['_token'])) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            foreach (['operation', 'target', 'expectedRevision', 'requestId', 'q', 'page'] as $key) {
                if (isset($values[$key]) && !is_scalar($values[$key])) {
                    throw new UnprocessableEntityHttpException('Поля формы должны быть скалярами.');
                }
            }
        }
        $rawQ = $values['q'] ?? '';
        $q = is_string($rawQ) ? trim($rawQ) : '';
        if ($request->isMethod('POST') && mb_strlen($q) > 100) {
            throw new UnprocessableEntityHttpException('Поиск не длиннее 100 символов.');
        }
        $q = mb_substr($q, 0, 100);
        $page = filter_var($values['page'] ?? 1, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $page) {
            throw new UnprocessableEntityHttpException('Неверный номер страницы.');
        }
        $rawOperation = $values['operation'] ?? '';
        if ($request->isMethod('POST') && !is_string($rawOperation)) {
            throw new UnprocessableEntityHttpException('Неизвестное действие.');
        }
        $operation = $request->isMethod('POST') && is_string($rawOperation) ? ListingMappingOperation::tryFrom($rawOperation) : ListingMappingOperation::SET;
        if (null === $operation) {
            throw new UnprocessableEntityHttpException('Неизвестное действие.');
        }
        $submittedTarget = null;
        if ($request->isMethod('POST') && ListingMappingOperation::SET === $operation) {
            $targetValue = $values['target'] ?? '';
            if (!is_string($targetValue)) {
                throw new UnprocessableEntityHttpException('Выберите корректную позицию.');
            }
            if (1 !== preg_match('/^(product|variant):([a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12})$/iD', $targetValue, $match)) {
                throw new UnprocessableEntityHttpException('Выберите корректную позицию.');
            }
            $values['target'] = strtolower($targetValue);
            $request->request->set('target', $values['target']);
            $submittedTarget = $this->targets->find($context['companyId'], $connectionId, strtolower($match[1]), $match[2]);
            if (null === $submittedTarget) {
                throw $this->createNotFoundException();
            }
        }
        $suggestions = $this->targets->suggest($context['companyId'], $connectionId, $context['source']?->supplierSku);
        $search = $this->targets->search($context['companyId'], $connectionId, $q, $page);
        $choices = [];
        foreach (array_merge($suggestions->items, $search->items) as $target) {
            $choices[$target->getValue()] = $target;
        }
        if (null !== $context['state']->target) {
            $choices[$context['state']->target->getValue()] = $context['state']->target;
        }
        $submittedRequestId = $values['requestId'] ?? null;
        if (null !== $submittedTarget && is_string($submittedRequestId)
            && 1 === preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $submittedRequestId)
            && null !== $this->revisions->acceptedRequest($context['companyId'], $submittedRequestId)) {
            // An accepted request survives later catalog/search changes. The Action
            // still verifies its complete payload, actor, connection and CAS value.
            $choices[$submittedTarget->getValue()] = $submittedTarget;
        }
        $expected = $values['expectedRevision'] ?? (string) $context['state']->revision;
        $requestId = $values['requestId'] ?? Uuid::uuid7()->toString();
        if (!is_scalar($expected) || !is_string($requestId)) {
            throw new UnprocessableEntityHttpException('Неверная версия формы.');
        }
        $setData = ['operation' => 'set', 'expectedRevision' => (string) $expected, 'requestId' => $requestId,
            'target' => $request->isMethod('POST') && ListingMappingOperation::SET === $operation ? ($values['target'] ?? null) : $context['state']->target?->getValue()];
        $clearData = ['operation' => 'clear', 'expectedRevision' => (string) $expected,
            'requestId' => $request->isMethod('POST') && ListingMappingOperation::CLEAR === $operation ? $requestId : Uuid::uuid7()->toString()];
        $setForm = $this->createForm(MoySkladListingMappingType::class, $setData, ['targets' => $choices]);
        $clearForm = $this->createForm(MoySkladListingMappingType::class, $clearData, ['operation' => 'clear']);
        $active = ListingMappingOperation::CLEAR === $operation ? $clearForm : $setForm;
        $active->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($active->isSubmitted() && $active->isValid()) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }
            /** @var array{operation:string,expectedRevision:string,requestId:string,target?:string} $data */
            $data = $active->getData();
            $targetParts = ListingMappingOperation::SET === $operation ? explode(':', $data['target'] ?? '', 2) : [];
            try {
                $action(new ChangeListingMappingCommand($context['companyId'], (string) $user->getId(), $connectionId, $listingId, $operation,
                    $targetParts[0] ?? null, $targetParts[1] ?? null, (int) $data['expectedRevision'], $data['requestId']));
                $this->addFlash('success', ListingMappingOperation::CLEAR === $operation ? 'Связь снята. История сохранена.' : 'Связь сохранена.');

                return $this->redirectToRoute('moysklad_listing_mappings', ['connectionId' => $connectionId, 'marketplace' => $marketplace], 303);
            } catch (ListingMappingException $error) {
                if ('not_found' === $error->reason) {
                    throw $this->createNotFoundException();
                }
                $active->addError(new FormError($error->getMessage()));
                $status = $error->getStatusCode();
            }
        }
        $ready = $this->targets->isReady($context['companyId'], $connectionId);
        $params = ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'listingId' => $listingId];
        $token = $this->csrfTokens->getToken($csrfId)->getValue();

        return $this->render('moy_sklad/mappings/form.html.twig', $context + [
            'setForm' => $setForm->createView(), 'clearForm' => $clearForm->createView(), 'csrfToken' => $token,
            'choices' => $choices, 'suggestions' => $suggestions, 'searchPage' => $search, 'q' => $q,
            'mappingConnectionId' => $connectionId, 'mappingMarketplace' => $marketplace, 'routeParams' => $params,
            'providerName' => MarketplaceType::from($marketplace)->getDisplayName(), 'catalogReady' => $ready,
            'canSet' => null !== $context['source'] && $ready && $this->isGranted(ModuleAccess::MARKETPLACE_WRITE),
            'canClear' => $context['state']->revision > 0 && 'unmapped' !== $context['state']->status && $this->isGranted(ModuleAccess::MARKETPLACE_WRITE),
            'formExpectedRevision' => (string) $expected, 'formRequestId' => $requestId, 'clearRequestId' => $clearData['requestId'],
            'productSync' => $this->syncStatus->forConnections($context['companyId'], [$connectionId], 'product')[$connectionId] ?? null,
            'variantSync' => $this->syncStatus->forConnections($context['companyId'], [$connectionId], 'variant')[$connectionId] ?? null,
        ], new Response(status: $status));
    }

    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/{listingId}/history', name: 'moysklad_listing_mapping_history', requirements: ['connectionId' => Requirement::UUID, 'listingId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['GET'])]
    public function history(string $connectionId, string $marketplace, string $listingId, Request $request): Response
    {
        $connectionId = strtolower($connectionId);
        $listingId = strtolower($listingId);
        $context = $this->context($connectionId, $marketplace, $listingId);
        $page = $request->query->getInt('page', 1);
        if ($page < 1) {
            throw new UnprocessableEntityHttpException('Неверный номер страницы.');
        }

        return $this->render('moy_sklad/mappings/history.html.twig', $context + [
            'history' => $this->mappings->history($context['companyId'], $connectionId, $listingId, $page),
            'mappingConnectionId' => $connectionId, 'mappingMarketplace' => $marketplace,
            'routeParams' => ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'listingId' => $listingId],
        ]);
    }

    /** @return array{companyId:string,connection:MoySkladConnection,source:?ListingMetaDTO,state:ListingMappingState} */
    private function context(string $connectionId, string $marketplace, string $listingId): array
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $connection = $this->connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $state = $this->mappings->currentForListings($companyId, $connectionId, [$listingId])[strtolower($listingId)];
        $source = $this->listings->getListingsMetaByIds($companyId, [$listingId])[strtolower($listingId)] ?? null;
        if ((null === $source && 0 === $state->revision) || ($source->marketplace ?? ($state->listingSnapshot['marketplace'] ?? null)) !== $marketplace) {
            throw $this->createNotFoundException();
        }

        return ['companyId' => $companyId, 'connection' => $connection, 'source' => $source, 'state' => $state];
    }
}
