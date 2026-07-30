<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Application\DTO\CounterpartyFormData;
use App\Company\Application\SaveCounterpartyAction;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType as CounterpartyTypeEnum;
use App\Company\Exception\CounterpartyInnAlreadyExistsException;
use App\Company\Form\CounterpartyType;
use App\Company\Repository\CounterpartyRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/counterparties')]
class CounterpartyController extends AbstractController
{
    #[Route('/', name: 'counterparty_index', methods: ['GET'])]
    public function index(Request $request, CounterpartyRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $q = $request->query->get('q');
        $type = $request->query->get('type');
        $showArchived = $request->query->getBoolean('show_archived', false);
        $typeEnum = $type ? CounterpartyTypeEnum::tryFrom($type) : null;

        $items = $repo->findByFilters($company, $typeEnum, $q, $showArchived, ['name' => 'ASC']);

        return $this->render('counterparty/index.html.twig', [
            'items' => $items,
            'q' => $q,
            'type' => $type,
            'show_archived' => $showArchived,
        ]);
    }

    #[Route('/new', name: 'counterparty_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SaveCounterpartyAction $save, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();

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
    public function archive(string $id, Request $request, CounterpartyRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $counterparty = $this->findForCompany($repo, $id, $company->getId());

        if ($this->isCsrfTokenValid('archive'.$counterparty->getId(), $request->request->get('_token'))) {
            $counterparty->archive();
            $em->flush();
            $this->addFlash('success', 'Контрагент архивирован');
        }

        return $this->redirectToRoute('counterparty_index');
    }

    #[Route('/{id}/unarchive', name: 'counterparty_unarchive', methods: ['POST'])]
    public function unarchive(string $id, Request $request, CounterpartyRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $counterparty = $this->findForCompany($repo, $id, $company->getId());

        if ($this->isCsrfTokenValid('unarchive'.$counterparty->getId(), $request->request->get('_token'))) {
            $counterparty->restore();
            $em->flush();
            $this->addFlash('success', 'Контрагент восстановлён');
        }

        return $this->redirectToRoute('counterparty_index');
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
