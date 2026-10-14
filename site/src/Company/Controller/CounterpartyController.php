<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Application\DTO\CounterpartyFormData;
use App\Company\Application\SaveCounterpartyAction;
use App\Company\Domain\Service\CounterpartyDisplayNameSuggestion;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType as CounterpartyTypeEnum;
use App\Company\Exception\CounterpartyInnAlreadyExistsException;
use App\Company\Form\CounterpartyType;
use App\Company\Infrastructure\Http\CounterpartyListQuery;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/counterparties')]
class CounterpartyController extends AbstractController
{
    private const PER_PAGE = 50;
    private const MAX_PER_PAGE = 200;

    #[Route('/', name: 'counterparty_index', methods: ['GET'])]
    public function index(Request $request, CounterpartyRepository $repo, ActiveCompanyService $companyService, CounterpartyDisplayNameSuggestion $suggestion): Response
    {
        $company = $companyService->getActiveCompany();
        $q = $request->query->get('q');
        $type = $request->query->get('type');
        $showArchived = $request->query->getBoolean('show_archived', false);
        $typeEnum = $type ? CounterpartyTypeEnum::tryFrom($type) : null;

        // query->all(), а не get(): массив (?page[]=1) иначе даёт 400 вместо 422.
        $parameters = $request->query->all();
        $page = filter_var($parameters['page'] ?? '1', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($parameters['limit'] ?? (string) self::PER_PAGE, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PER_PAGE]]);
        if (false === $page || false === $limit) {
            throw new UnprocessableEntityHttpException('Номер страницы и размер списка должны быть целыми числами: страница от 1, размер от 1 до 200.');
        }

        try {
            $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(
                // Без join'ов: fetchJoinCollection не нужен, лишний запрос id не делаем.
                new QueryAdapter($repo->createListQueryBuilder($company, $typeEnum, $q, $showArchived), false),
                $page,
                $limit,
            );
        } catch (OutOfRangeCurrentPageException $exception) {
            throw new UnprocessableEntityHttpException('Страница не найдена.', $exception);
        }

        $items = iterator_to_array($pager->getCurrentPageResults(), false);

        // Предложения нормализации — только для строк страницы (≤ limit вызовов чистых правил).
        $suggestions = [];
        foreach ($items as $item) {
            $suggested = $suggestion->for($item);
            if (null !== $suggested) {
                $suggestions[$item->getId()] = $suggested;
            }
        }

        return $this->render('counterparty/index.html.twig', [
            'pager' => $pager,
            'items' => $items,
            'suggestions' => $suggestions,
            'archivedCount' => $showArchived ? $repo->countArchivedInList($company, $typeEnum, $q) : 0,
            'defaultLimit' => self::PER_PAGE,
            'q' => $q,
            'type' => $type,
            'show_archived' => $showArchived,
        ]);
    }

    #[Route('/new', name: 'counterparty_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SaveCounterpartyAction $save, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();

        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $form = $this->createForm(CounterpartyType::class, new CounterpartyFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->trySave($form, $save, $company, null)) {
            $this->addFlash('success', 'Контрагент добавлен');

            return $this->redirectToRoute('counterparty_index');
        }

        return $this->render('counterparty/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'counterparty_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, CounterpartyRepository $repo, SaveCounterpartyAction $save, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();

        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $counterparty = $this->findForCompany($repo, $id, $company->getId());

        $data = new CounterpartyFormData();
        $data->name = $counterparty->getName();
        $data->inn = $counterparty->getInn();
        $data->kpp = $counterparty->getKpp();
        $data->type = $counterparty->getType();

        $form = $this->createForm(CounterpartyType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->trySave($form, $save, $company, $counterparty)) {
            $this->addFlash('success', 'Контрагент обновлён');

            return $this->redirectToRoute('counterparty_index');
        }

        return $this->render('counterparty/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $counterparty,
        ]);
    }

    #[Route('/{id}/archive', name: 'counterparty_archive', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function archive(string $id, Request $request, CounterpartyRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $counterparty = $this->findForCompany($repo, $id, $company->getId());

        if ($this->isCsrfTokenValid('archive'.$counterparty->getId(), $request->request->get('_token'))) {
            $counterparty->archive();
            $em->flush();
            $this->addFlash('success', 'Контрагент архивирован');
        }

        return $this->backToList($request, $repo, $company);
    }

    #[Route('/{id}/unarchive', name: 'counterparty_unarchive', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function unarchive(string $id, Request $request, CounterpartyRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $counterparty = $this->findForCompany($repo, $id, $company->getId());

        if ($this->isCsrfTokenValid('unarchive'.$counterparty->getId(), $request->request->get('_token'))) {
            $counterparty->restore();
            $em->flush();
            $this->addFlash('success', 'Контрагент восстановлён');
        }

        return $this->backToList($request, $repo, $company);
    }

    /**
     * Назад к списку с теми же фильтрами и страницей (их несёт адрес формы архивации), чтобы
     * не терять место в длинном списке. Страница ограничивается последней существующей:
     * архивация единственной строки последней страницы иначе вела бы на 422.
     */
    private function backToList(Request $request, CounterpartyRepository $repo, Company $company): Response
    {
        $query = CounterpartyListQuery::fromRequest($request);
        $page = filter_var($query['page'] ?? '1', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        unset($query['page']);
        if (\is_int($page) && $page > 1) {
            $limit = filter_var($query['limit'] ?? self::PER_PAGE, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PER_PAGE]]);
            $limit = \is_int($limit) ? $limit : self::PER_PAGE;
            $type = isset($query['type']) ? CounterpartyTypeEnum::tryFrom($query['type']) : null;
            $showArchived = filter_var($query['show_archived'] ?? false, \FILTER_VALIDATE_BOOL);
            $total = (new QueryAdapter($repo->createListQueryBuilder($company, $type, $query['q'] ?? null, $showArchived), false))->getNbResults();
            $page = min($page, max(1, (int) ceil($total / $limit)));
            if ($page > 1) {
                $query['page'] = $page;
            }
        }

        return $this->redirectToRoute('counterparty_index', $query);
    }

    private function findForCompany(CounterpartyRepository $repo, string $id, string $companyId): Counterparty
    {
        $counterparty = $repo->find($id);
        if (!$counterparty instanceof Counterparty || !$counterparty->belongsToCompany($companyId)) {
            throw $this->createNotFoundException();
        }

        return $counterparty;
    }

    private function trySave(FormInterface $form, SaveCounterpartyAction $save, Company $company, ?Counterparty $counterparty): bool
    {
        /** @var CounterpartyFormData $data */
        $data = $form->getData();

        try {
            $save($company, $data, $counterparty);

            return true;
        } catch (CounterpartyInnAlreadyExistsException $e) {
            $form->get('inn')->addError(new FormError($e->getMessage()));

            return false;
        }
    }
}
