<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\ChangeMappingAutoApplyAction;
use App\MoySklad\Infrastructure\Query\MappingCharacteristicsQuery;
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
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class UpdateMappingAutoApplyController extends AbstractController
{
    public static function csrfId(string $companyId, string $connectionId, string $marketplace): string
    {
        return 'moysklad_mapping_auto_apply'.$companyId.$connectionId.$marketplace;
    }

    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/auto-apply', name: 'moysklad_mapping_auto_apply', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['POST'])]
    public function __invoke(string $connectionId, string $marketplace, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections,
        MappingCharacteristicsQuery $characteristics, ChangeMappingAutoApplyAction $action): Response
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $values = $request->request->all();
        if (!is_string($values['_token'] ?? null) || !$this->isCsrfTokenValid(self::csrfId($companyId, $connectionId, $marketplace), $values['_token'])) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $enabled = $values['enabled'] ?? '';
        $size = $values['sizeCharacteristicId'] ?? '';
        if (!in_array($enabled, ['', '1'], true) || !is_string($size) || mb_strlen($size) > 255) {
            throw new UnprocessableEntityHttpException('Некорректные параметры автоприменения.');
        }
        if ('' !== $size && null === $characteristics->find($companyId, $connectionId, $size)) {
            throw $this->createNotFoundException();
        }
        $action($companyId, $connectionId, $marketplace, '1' === $enabled, (string) $actor->getId(), '' === $size ? null : $size);
        $this->addFlash('success', '1' === $enabled ? 'Автоприменение включено: однозначные предложения будут приниматься каждый день в 05:30.' : 'Автоприменение выключено.');

        return $this->redirectToRoute('moysklad_listing_mappings', ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'sizeCharacteristicId' => $size], 303);
    }
}
