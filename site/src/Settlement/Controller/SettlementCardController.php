<?php

declare(strict_types=1);

namespace App\Settlement\Controller;

use App\Company\Facade\CounterpartyFacade;
use App\Company\Security\ModuleAccess;
use App\Settlement\Application\DTO\SettlementReportFilter;
use App\Settlement\Facade\SettlementFacadeInterface;
use App\Settlement\Form\SettlementReportFilterType;
use App\Settlement\Query\SettlementUiQuery;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Adapter\CallbackAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settlements/counterparties/{id}', name: 'settlement_card', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class SettlementCardController extends AbstractController
{
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, SettlementUiQuery $query, SettlementFacadeInterface $settlements, CounterpartyFacade $parties, string $id): Response
    {
        $company = $activeCompany->getActiveCompany();
        $companyId = (string) $company->getId();
        $labels = $parties->getLabelsByIds($companyId, [$id]);
        if (!isset($labels[$id])) {
            throw $this->createNotFoundException('Контрагент не найден.');
        }
        $book = $query->book($companyId);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $from = $book?->getStartDate() ?? $today;
        $defaults = ['from' => $from, 'to' => $today, 'counterparty' => null, 'limit' => 50];
        $form = $this->createForm(SettlementReportFilterType::class, $defaults, ['company_id' => $companyId, 'keep_id' => $id]);
        $form->remove('counterparty');
        if ([] !== $request->query->all()) {
            $form->submit($request->query->all(), false);
        }
        $report = null;
        $pager = null;
        if (null !== $book && (!$form->isSubmitted() || $form->isValid())) {
            /** @var array{from:\DateTimeImmutable,to:\DateTimeImmutable,counterparty:?string,limit:int} $data */ $data = $form->getData();
            try {
                $page = filter_var($request->query->all()['page'] ?? '1', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (false === $page) {
                    throw new \DomainException('Некорректная страница.');
                }
                $filter = new SettlementReportFilter($data['from'], $data['to'], 'RUB', null, $page, $data['limit']);
                $report = $settlements->card($companyId, $id, $filter);
                $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(new CallbackAdapter(static fn () => max(0, $report->total), static fn (int $offset, int $length): array => $report->rows), $page, $data['limit']);
            } catch (\DomainException|OutOfRangeCurrentPageException $exception) {
                $form->addError(new FormError($exception->getMessage()));
                $report = null;
            }
        }

        return $this->render('settlement/card.html.twig', ['activeCompany' => $company, 'book' => $book, 'filterForm' => $form, 'report' => $report, 'pager' => $pager, 'counterpartyId' => $id, 'counterpartyName' => $labels[$id],  'canWrite' => $this->isGranted(ModuleAccess::FINANCE_WRITE)], new Response(status: $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }
}
