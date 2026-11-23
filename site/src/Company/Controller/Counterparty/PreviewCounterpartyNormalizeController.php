<?php

declare(strict_types=1);

namespace App\Company\Controller\Counterparty;

use App\Company\Application\RefreshCounterpartyDisplayNamesAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** «Нормализовать названия»: предпросмотр «было → станет» по активной компании, без записи. */
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class PreviewCounterpartyNormalizeController extends AbstractController
{
    /** Сколько строк «было → станет» показать; счётчики — по всем. */
    private const SAMPLES = 2000;

    public function __construct(
        private readonly RefreshCounterpartyDisplayNamesAction $refresh,
        private readonly ActiveCompanyService $activeCompany,
    ) {
    }

    #[Route('/counterparties/display-names/normalize', name: 'counterparty_normalize_preview', methods: ['GET'])]
    public function __invoke(): Response
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();

        return $this->render('counterparty/normalize.html.twig', [
            'result' => ($this->refresh)(true, self::SAMPLES, $companyId),
            'companyId' => $companyId,
            'samplesLimit' => self::SAMPLES,
        ]);
    }
}
