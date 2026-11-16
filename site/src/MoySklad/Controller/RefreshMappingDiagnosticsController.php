<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Security\ModuleAccess;
use App\MoySklad\Infrastructure\Query\MappingCharacteristicsQuery;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Message\DiagnoseMappingsMessage;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class RefreshMappingDiagnosticsController extends AbstractController
{
    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/diagnostics', name: 'moysklad_mapping_diagnostics_refresh', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['POST'])]
    public function __invoke(string $connectionId, string $marketplace, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, MappingCharacteristicsQuery $characteristics, MessageBusInterface $bus): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $values = $request->request->all();
        if (!is_string($values['_token'] ?? null) || !$this->isCsrfTokenValid('moysklad_mapping_diagnostics'.$companyId.$connectionId.$marketplace, $values['_token'])) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $id = $values['runId'] ?? null;
        $characteristic = $values['diagnosticSizeCharacteristicId'] ?? '';
        if (!is_string($id) || !Uuid::isValid($id) || !is_string($characteristic) || mb_strlen($characteristic) > 255) {
            throw new UnprocessableEntityHttpException('Некорректный запрос диагностики.');
        }
        $characteristic = '' === $characteristic ? null : $characteristic;
        if (null !== $characteristic && null === $characteristics->find($companyId, $connectionId, $characteristic)) {
            throw $this->createNotFoundException();
        }
        $bus->dispatch(new DiagnoseMappingsMessage($companyId, $connectionId, $marketplace, strtolower($id), $characteristic));
        $this->addFlash('success', 'Проверка всего кабинета поставлена в очередь. Обновите страницу, чтобы увидеть прогресс.');

        return $this->redirectToRoute('moysklad_listing_mappings', ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'sizeCharacteristicId' => $characteristic ?? ''], 303);
    }
}
