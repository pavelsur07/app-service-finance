<?php

namespace App\Controller;

use App\Entity\Counterparty;
use App\Enum\CounterpartyType as CounterpartyTypeEnum;
use App\Form\CounterpartyType;
use App\Repository\CounterpartyRepository;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function new(Request $request, EntityManagerInterface $em, ActiveCompanyService $companyService, CounterpartyRepository $repo): Response
    {
        $company = $companyService->getActiveCompany();
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, '', CounterpartyTypeEnum::LEGAL_ENTITY);

        $form = $this->createForm(CounterpartyType::class, $counterparty);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($counterparty->getInn()) {
                $existing = $repo->findOneBy(['company' => $company, 'inn' => $counterparty->getInn()]);
                if ($existing) {
                    $form->get('inn')->addError(new FormError('Контрагент с таким ИНН уже существует'));
                }
            }

            if ($form->isValid()) {
                $em->persist($counterparty);
                $em->flush();
                $this->addFlash('success', 'Контрагент добавлен');

                return $this->redirectToRoute('counterparty_index');
            }
        }

        return $this->render('counterparty/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'counterparty_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, CounterpartyRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $counterparty = $repo->find($id);
        if (!$counterparty || $counterparty->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(CounterpartyType::class, $counterparty);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($counterparty->getInn()) {
                $existing = $repo->findOneBy(['company' => $company, 'inn' => $counterparty->getInn()]);
                if ($existing && $existing->getId() !== $counterparty->getId()) {
                    $form->get('inn')->addError(new FormError('Контрагент с таким ИНН уже существует'));
                }
            }

            if ($form->isValid()) {
                $counterparty->setUpdatedAt(new \DateTimeImmutable());
                $em->flush();
                $this->addFlash('success', 'Контрагент обновлён');

                return $this->redirectToRoute('counterparty_index');
            }
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
        $counterparty = $repo->find($id);
        if (!$counterparty || $counterparty->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('archive'.$counterparty->getId(), $request->request->get('_token'))) {
            $counterparty->setIsArchived(true);
            $counterparty->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', 'Контрагент архивирован');
        }

        return $this->redirectToRoute('counterparty_index');
    }

    #[Route('/{id}/unarchive', name: 'counterparty_unarchive', methods: ['POST'])]
    public function unarchive(string $id, Request $request, CounterpartyRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $counterparty = $repo->find($id);
        if (!$counterparty || $counterparty->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('unarchive'.$counterparty->getId(), $request->request->get('_token'))) {
            $counterparty->setIsArchived(false);
            $counterparty->setUpdatedAt(new \DateTimeImmutable());
            $em->flush();
            $this->addFlash('success', 'Контрагент восстановлен');
        }

        return $this->redirectToRoute('counterparty_index');
    }
}
