<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\ChangeMappingSettingsAction;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
final class UpdateMappingSettingsController extends AbstractController
{
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    #[Route('/moy-sklad/connections/{connectionId}/mappings/wildberries/settings', name: 'moysklad_mapping_settings', requirements: ['connectionId' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(string $connectionId, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, ChangeMappingSettingsAction $action): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $values = $request->request->all();
        if (!is_string($values['_token'] ?? null) || !$this->isCsrfTokenValid('moysklad_mapping_settings'.$companyId.$connectionId, $values['_token'])) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $enabled = $values['enabled'] ?? '0';
        if (!in_array($enabled, ['0', '1'], true)) {
            throw new UnprocessableEntityHttpException('Некорректное значение настройки.');
        }
        $action($companyId, $connectionId, '1' === $enabled);
        $this->addFlash('success', 'Настройка сохранена. Обновите диагностику и предпросмотр соответствий.');

        return $this->redirectToRoute('moysklad_listing_mappings', ['connectionId' => $connectionId, 'marketplace' => 'wildberries'], 303);
    }
}
