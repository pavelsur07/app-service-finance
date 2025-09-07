<?php

namespace App\Controller;

use App\Entity\Document;
use App\Form\DocumentType;
use App\Repository\DocumentRepository;
use App\Repository\PLCategoryRepository;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/documents')]
class DocumentController extends AbstractController
{
    #[Route('/', name: 'document_index', methods: ['GET'])]
    public function index(DocumentRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findByCompany($company);
        return $this->render('document/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'document_new', methods: ['GET', 'POST'])]
    public function new(Request $request, DocumentRepository $repo, PLCategoryRepository $catRepo, CounterpartyRepository $cpRepo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $document = new Document(Uuid::uuid4()->toString(), $company);

        $categories = $catRepo->findTreeByCompany($company);
        $counterparties = $cpRepo->findBy(['company' => $company]);
        $form = $this->createForm(DocumentType::class, $document, [
            'categories' => $categories,
            'counterparties' => $counterparties,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($document);
            $em->flush();
            return $this->redirectToRoute('document_index');
        }

        return $this->render('document/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'document_show', methods: ['GET'])]
    public function show(Document $document, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        return $this->render('document/show.html.twig', [
            'item' => $document,
        ]);
    }

    #[Route('/{id}/edit', name: 'document_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Document $document, PLCategoryRepository $catRepo, CounterpartyRepository $cpRepo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $categories = $catRepo->findTreeByCompany($company);
        $counterparties = $cpRepo->findBy(['company' => $company]);
        $form = $this->createForm(DocumentType::class, $document, [
            'categories' => $categories,
            'counterparties' => $counterparties,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            return $this->redirectToRoute('document_index');
        }

        return $this->render('document/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $document,
        ]);
    }

    #[Route('/{id}/delete', name: 'document_delete', methods: ['POST'])]
    public function delete(Request $request, Document $document, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$document->getId(), $request->request->get('_token'))) {
            $em->remove($document);
            $em->flush();
        }

        return $this->redirectToRoute('document_index');
    }
}
