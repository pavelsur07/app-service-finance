<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\AcceptStoreAccountSuggestionsAction;
use App\MoySklad\Application\Action\SaveStoreBalanceLinkAction;
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
    public function __construct(private readonly ActiveCompanyService $activeCompany, private readonly MoySkladConnectionWriteRepository $connections)
    {
    }

    #[Route('/moy-sklad/connections/{connectionId}/stores/{storeExternalId}/balance-link', name: 'moysklad_store_balance_link', requirements: ['connectionId' => Requirement::UUID, 'storeExternalId' => Requirement::UUID], methods: ['POST'])]
    public function link(string $connectionId, string $storeExternalId, Request $request, SaveStoreBalanceLinkAction $action): Response
    {
        [$companyId, $connectionId, $actorId, $values] = $this->guard($connectionId, $request);
        $target = $values['target'] ?? '';
        if (!is_string($target) || ('' !== $target && 'excluded' !== $target && !Uuid::isValid($target))) {
            throw new UnprocessableEntityHttpException('Некорректный счёт.');
        }
        // «Принять» предложение не перезаписывает назначение, сделанное после показа страницы.
        $onlyIfUnassigned = '1' === ($values['onlyIfUnassigned'] ?? null);
        try {
            $saved = $action($companyId, $connectionId, $storeExternalId, Uuid::isValid($target) ? $target : null, 'excluded' === $target, $actorId, $onlyIfUnassigned);
            $saved ? $this->addFlash('success', 'Назначение склада сохранено.') : $this->addFlash('warning', 'Склад уже назначен — предложение не применено.');
        } catch (StoreBalanceException $error) {
            if ('store_not_found' === $error->reason) {
                throw $this->createNotFoundException();
            }
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->back($connectionId, $values);
    }

    #[Route('/moy-sklad/connections/{connectionId}/stores/accept-suggestions', name: 'moysklad_store_accept_suggestions', requirements: ['connectionId' => Requirement::UUID], methods: ['POST'])]
    public function acceptSuggestions(string $connectionId, Request $request, AcceptStoreAccountSuggestionsAction $action): Response
    {
        [$companyId, $connectionId, $actorId, $values] = $this->guard($connectionId, $request);
        try {
            $result = $action($companyId, $connectionId, $actorId);
            $this->addFlash('success', sprintf('Принято предложений: %d.', $result['accepted']));
            if ($result['failed'] > 0) {
                $this->addFlash('warning', sprintf('Не принято: %d — назначьте эти склады вручную.', $result['failed']));
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
        return $this->redirectToRoute('moysklad_stores', ['connectionId' => $connectionId] + ('unassigned' === ($values['filter'] ?? null) ? ['filter' => 'unassigned'] : []), 303);
    }
}
