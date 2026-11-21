<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\StartMappingBulkJobAction;
use App\MoySklad\Application\Command\StartMappingBulkJobCommand;
use App\MoySklad\Exception\ListingMappingException;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Запуск пакета принятия предложений: все предложения отчёта (scope report) или отмеченные строки (selected). */
#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class StartMappingBulkJobController extends AbstractController
{
    public static function csrfId(string $companyId, string $connectionId, string $marketplace): string
    {
        return 'moysklad_mapping_bulk'.$companyId.$connectionId.$marketplace;
    }

    #[Route('/moy-sklad/connections/{connectionId}/mappings/{marketplace}/bulk', name: 'moysklad_mapping_bulk_start', requirements: ['connectionId' => Requirement::UUID, 'marketplace' => 'ozon|wildberries'], methods: ['POST'])]
    public function __invoke(string $connectionId, string $marketplace, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $connections, StartMappingBulkJobAction $action): Response
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
        $jobId = $values['jobId'] ?? null;
        $scope = $values['scope'] ?? null;
        $ids = $values['listingIds'] ?? [];
        if (!is_string($jobId) || !Uuid::isValid($jobId) || !in_array($scope, ['report', 'selected'], true) || !is_array($ids) || !array_is_list($ids)
            || ('selected' === $scope) !== ([] !== $ids) || count($ids) > 100 || count($ids) !== count(array_unique($ids))) {
            throw new UnprocessableEntityHttpException('Выберите строки-предложения из текущего отчёта.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new UnprocessableEntityHttpException('Некорректный листинг.');
            }
        }
        /** @var list<string> $ids */
        $ids = array_map(strtolower(...), $ids);
        try {
            $action(new StartMappingBulkJobCommand($companyId, (string) $actor->getId(), $connectionId, $marketplace, strtolower($jobId), $scope, $ids));
            $this->addFlash('success', 'Пакет запущен: предложения принимаются в фоне. Обновите страницу, чтобы увидеть прогресс.');
        } catch (ListingMappingException $error) {
            if (in_array($error->reason, ['invalid_selection', 'idempotency_conflict', 'not_found'], true)) {
                throw new HttpException($error->getStatusCode(), $error->getMessage());
            }
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('moysklad_listing_mappings', ['connectionId' => $connectionId, 'marketplace' => $marketplace], 303);
    }
}
