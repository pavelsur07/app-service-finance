<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\SaveStoreBalanceLinksAction;
use App\MoySklad\Application\Action\SaveStoreBalanceSettingsAction;
use App\MoySklad\Exception\StoreBalanceException;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_READ)]
#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class SaveStoreBalanceController extends AbstractController
{
    /** id МойСклад похож на UUID, но вариант не RFC 4122 (`…-11e5-7a69-…`) — Uuid::isValid нестрогий, Requirement::UUID давал 404. */
    private const MOYSKLAD_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const MAX_ROWS = 1000;

    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly MoySkladConnectionWriteRepository $connections)
    {
    }

    #[Route('/moy-sklad/connections/{connectionId}/stores/balance-links', name: 'moysklad_store_balance_links', requirements: ['connectionId' => Requirement::UUID], methods: ['POST'])]
    public function links(string $connectionId, Request $request, SaveStoreBalanceLinksAction $action): Response
    {
        [$companyId, $connectionId, $actorId, $values] = $this->guard($connectionId, $request);
        $links = $values['links'] ?? [];
        if (!is_array($links) || \count($links) > self::MAX_ROWS) {
            throw new UnprocessableEntityHttpException('Некорректные назначения складов.');
        }
        $rows = [];
        foreach ($links as $storeExternalId => $row) {
            $storeExternalId = strtolower((string) $storeExternalId);
            if (1 !== preg_match(self::MOYSKLAD_ID, $storeExternalId) || !is_array($row) || !self::isTarget($row['target'] ?? null) || !self::isTarget($row['was'] ?? null)) {
                throw new UnprocessableEntityHttpException('Некорректные назначения складов.');
            }
            $rows[$storeExternalId] = ['target' => strtolower($row['target']), 'was' => strtolower($row['was'])];
        }
        try {
            $result = $action($companyId, $connectionId, $rows, $actorId);
            $this->addFlash('success', sprintf('Сохранено: %d.', $result['saved']));
            if ($result['skipped'] > 0) {
                $this->addFlash('warning', sprintf('Пропущено: %d — склад изменили в другом окне, проверьте его.', $result['skipped']));
            }
            foreach (array_count_values($result['failed']) as $message => $count) {
                $this->addFlash('danger', sprintf('Не сохранено: %d — %s', $count, $message));
            }
        } catch (StoreBalanceException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->back($connectionId, $values);
    }

    #[Route('/moy-sklad/connections/{connectionId}/stores/balance-settings', name: 'moysklad_store_balance_settings', requirements: ['connectionId' => Requirement::UUID], methods: ['POST'])]
    public function settings(string $connectionId, Request $request, SaveStoreBalanceSettingsAction $action): Response
    {
        [$companyId, $connectionId, $actorId, $values] = $this->guard($connectionId, $request);
        $counter = $values['counterAccountId'] ?? '';
        $enabled = $values['enabled'] ?? '';
        if (!is_string($counter) || ('' !== $counter && !Uuid::isValid($counter)) || !in_array($enabled, ['', '1'], true)) {
            throw new UnprocessableEntityHttpException('Некорректные параметры записи в Баланс.');
        }
        try {
            $action($companyId, $connectionId, '' === $counter ? null : $counter, '1' === $enabled, $actorId);
            $this->addFlash('success', '1' === $enabled ? 'Запись в Баланс включена: остатки складов проводятся каждый день в 23:30.' : 'Настройки записи в Баланс сохранены.');
        } catch (StoreBalanceException $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->back($connectionId, $values);
    }

    /** @return array{string, string, string, array<string, mixed>} */
    private function guard(string $connectionId, Request $request): array
    {
        $connectionId = strtolower($connectionId);
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $this->connections->findByIdAndCompanyId($connectionId, $companyId) ?? throw $this->createNotFoundException();
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $values = $request->request->all();
        if (!is_string($values['_token'] ?? null) || !$this->isCsrfTokenValid(StoreBalanceController::csrfId($companyId, $connectionId), $values['_token'])) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return [$companyId, $connectionId, (string) $actor->getId(), $values];
    }

    /** @param array<string, mixed> $values */
    private function back(string $connectionId, array $values): Response
    {
        $filter = $values['filter'] ?? null;

        return $this->redirectToRoute('moysklad_stores', ['connectionId' => $connectionId] + (\in_array($filter, StoreBalanceController::FILTERS, true) ? ['filter' => $filter] : []), 303);
    }

    private static function isTarget(mixed $value): bool
    {
        return is_string($value) && ('' === $value || 'excluded' === $value || Uuid::isValid($value));
    }
}
