<?php

namespace App\Controller;

use App\Entity\Document;
use App\Enum\PlNature;
use App\Form\DocumentType;
use App\Repository\CounterpartyRepository;
use App\Repository\DocumentRepository;
use App\Repository\PLCategoryRepository;
use App\Service\ActiveCompanyService;
use App\Service\PlNatureResolver;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/documents')]
class DocumentController extends AbstractController
{
    #[Route('/', name: 'document_index', methods: ['GET'])]
    public function index(DocumentRepository $repo, ActiveCompanyService $companyService, PlNatureResolver $natureResolver): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findByCompany($company);

        $documentNatures = [];
        foreach ($items as $item) {
            $id = $item->getId() ?? spl_object_hash($item);
            $documentNatures[$id] = $this->buildNatureView($natureResolver->forDocument($item));
        }

        return $this->render('document/index.html.twig', [
            'items' => $items,
            'documentNatures' => $documentNatures,
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
    public function show(Document $document, ActiveCompanyService $companyService, PlNatureResolver $natureResolver): Response
    {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $documentNature = $this->buildNatureView($natureResolver->forDocument($document));
        $operationViews = [];
        foreach ($document->getOperations() as $operation) {
            $nature = $natureResolver->forOperation($operation);
            $category = $operation->getPlCategory();
            $natureValue = $nature?->value;
            $operationViews[] = [
                'operation' => $operation,
                'categoryName' => $category?->getName(),
                'nature' => $natureValue,
                'natureLabel' => $natureValue === PlNature::INCOME->value ? 'Доход' : ($natureValue === PlNature::EXPENSE->value ? 'Расход' : null),
                'badgeClass' => $natureValue === PlNature::INCOME->value ? 'bg-green-lt text-green' : ($natureValue === PlNature::EXPENSE->value ? 'bg-red-lt text-red' : ''),
                'isFallback' => $category === null && $nature !== null,
                'needsCategorization' => $category === null && $nature === null,
            ];
        }

        return $this->render('document/show.html.twig', [
            'item' => $document,
            'documentNature' => $documentNature,
            'operationViews' => $operationViews,
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

    /**
     * @return array{value: string|null, label: string, badgeClass: string}
     */
    private function buildNatureView(PlNature|string|null $nature): array
    {
        if ($nature instanceof PlNature) {
            return [
                'value' => $nature->value,
                'label' => $nature === PlNature::INCOME ? 'Доход' : 'Расход',
                'badgeClass' => $nature === PlNature::INCOME ? 'bg-green-lt text-green' : 'bg-red-lt text-red',
            ];
        }

        if ($nature === 'MIXED') {
            return [
                'value' => 'MIXED',
                'label' => 'Mixed',
                'badgeClass' => 'bg-purple-lt text-purple',
            ];
        }

        return [
            'value' => $nature ?? 'UNKNOWN',
            'label' => 'Неизвестно',
            'badgeClass' => 'bg-secondary',
        ];
    }
}
