<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Infrastructure\Http\DocumentListState;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Вход в раздел «Документы ОПиУ»: открывает журнал в том виде, в каком его оставили
 * (вкладка, фильтры, страница). Сюда ведут меню, хлебные крошки, «Отмена» и переходы
 * после сохранения; ссылки внутри журнала идут на сам список, иначе «Сбросить» не сбросит.
 */
#[IsGranted('ROLE_USER')]
final class DocumentListResumeController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DocumentListState $listState,
    ) {
    }

    // Приоритет выше `/documents/{id}`: см. DocumentOperationIndexController.
    #[Route('/documents/resume', name: 'document_list_resume', methods: ['GET'], priority: 10)]
    public function __invoke(): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $saved = $this->listState->recall($companyId);

        return $this->redirectToRoute($saved['route'], $saved['query']);
    }
}
