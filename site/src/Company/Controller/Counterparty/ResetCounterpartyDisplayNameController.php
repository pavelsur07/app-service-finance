<?php

declare(strict_types=1);

namespace App\Company\Controller\Counterparty;

use App\Company\Application\ResetCounterpartyDisplayNameAction;
use App\Company\Exception\CounterpartyDisplayNameNotApplicableException;
use App\Company\Exception\CounterpartyNotFoundException;
use App\Company\Infrastructure\Http\CounterpartyListQuery;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** «Вернуть исходное» — снять ручное отображаемое название в списке контрагентов. */
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class ResetCounterpartyDisplayNameController extends AbstractController
{
    public function __construct(
        private readonly ResetCounterpartyDisplayNameAction $reset,
        private readonly ActiveCompanyService $activeCompany,
    ) {
    }

    #[Route('/counterparties/{id}/display-name/reset', name: 'counterparty_reset_display_name', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();

        if (!$this->isCsrfTokenValid('reset_display_name'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Сессия устарела — обновите страницу и повторите.');

            return $this->redirectToRoute('counterparty_index', CounterpartyListQuery::fromRequest($request));
        }

        try {
            ($this->reset)($companyId, $id);
            $this->addFlash('success', 'Возвращено исходное название');
        } catch (CounterpartyNotFoundException) {
            throw $this->createNotFoundException();
        } catch (CounterpartyDisplayNameNotApplicableException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('counterparty_index', CounterpartyListQuery::fromRequest($request));
    }
}
