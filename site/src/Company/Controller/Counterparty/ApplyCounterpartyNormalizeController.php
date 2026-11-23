<?php

declare(strict_types=1);

namespace App\Company\Controller\Counterparty;

use App\Company\Application\RefreshCounterpartyDisplayNamesAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Применить» нормализацию названий активной компании. Пишет только авто-названия (ручные — никогда),
 * сырое название не меняется; повтор ничего не находит.
 */
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class ApplyCounterpartyNormalizeController extends AbstractController
{
    public function __construct(
        private readonly RefreshCounterpartyDisplayNamesAction $refresh,
        private readonly ActiveCompanyService $activeCompany,
    ) {
    }

    #[Route('/counterparties/display-names/normalize', name: 'counterparty_normalize_apply', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();

        if (!$this->isCsrfTokenValid('normalize_display_names'.$companyId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Сессия устарела — обновите страницу и повторите.');

            return $this->redirectToRoute('counterparty_normalize_preview');
        }

        $result = ($this->refresh)(false, 0, $companyId);
        $message = sprintf('Обновлено названий: %d.', $result->changed);
        if ($result->skippedConcurrent > 0) {
            $message .= sprintf(' Пропущено %d — их изменили во время обработки.', $result->skippedConcurrent);
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('counterparty_index');
    }
}
