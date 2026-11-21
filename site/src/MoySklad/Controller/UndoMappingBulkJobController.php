<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\StartMappingBulkUndoAction;
use App\MoySklad\Exception\ListingMappingException;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class UndoMappingBulkJobController extends AbstractController
{
    public static function csrfId(string $jobId): string
    {
        return 'moysklad_mapping_bulk_undo'.$jobId;
    }

    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/bulk/{jobId}/undo', name: 'moysklad_mapping_bulk_undo', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries', 'jobId' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(string $connectionId, string $marketplace, string $jobId, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, StartMappingBulkUndoAction $action): Response
    {
        $connectionId = strtolower($connectionId);
        $jobId = strtolower($jobId);
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $token = $request->request->all()['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid(self::csrfId($jobId), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $action($companyId, $connectionId, $marketplace, $jobId, (string) $actor->getId());
            $this->addFlash('success', 'Отмена пакета запущена. Обновите страницу, чтобы увидеть прогресс.');
        } catch (ListingMappingException $error) {
            if ('not_found' === $error->reason) {
                throw new HttpException(404, $error->getMessage());
            }
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('moysklad_mapping_bulk_show', ['connectionId' => $connectionId, 'marketplace' => $marketplace, 'jobId' => $jobId], 303);
    }
}
