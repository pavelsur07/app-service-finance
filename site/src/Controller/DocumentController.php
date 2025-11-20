<?php

namespace App\Controller;

use App\DTO\DocumentListDTO;
use App\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Enum\PlNature;
use App\Form\DocumentType;
use App\Repository\CounterpartyRepository;
use App\Repository\DocumentRepository;
use App\Repository\PLCategoryRepository;
use App\Service\ActiveCompanyService;
use App\Service\PlNatureResolver;
use App\Service\PLRegisterUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/documents')]
class DocumentController extends AbstractController
{
    public function __construct(private readonly PLRegisterUpdater $plRegisterUpdater)
    {
    }

    #[Route('/', name: 'document_index', methods: ['GET'])]
    public function index(Request $request, DocumentRepository $repo, ActiveCompanyService $companyService, PlNatureResolver $natureResolver): Response
    {
        $company = $companyService->getActiveCompany();
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = (int) $request->query->get('limit', 20);

        $pager = $repo->findByCompany(new DocumentListDTO($company, $page, $limit));
        $items = iterator_to_array($pager->getCurrentPageResults());

        $documentNatures = [];
        foreach ($items as $item) {
            $id = $item->getId() ?? spl_object_hash($item);
            $documentNatures[$id] = $this->buildNatureView($natureResolver->forDocument($item));
        }

        return $this->render('document/index.html.twig', [
            'items' => $items,
            'documentNatures' => $documentNatures,
            'pager' => $pager,
            'limit' => $pager->getMaxPerPage(),
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

            $this->plRegisterUpdater->updateForDocument($document);

            return $this->redirectToRoute('document_index');
        }

        return $this->render('document/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/copy', name: 'document_copy', methods: ['GET', 'POST'])]
    public function copy(
        Request $request,
        Document $document,
        PLCategoryRepository $catRepo,
        CounterpartyRepository $cpRepo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
    ): Response {
        $company = $companyService->getActiveCompany();
        if ($document->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $copy = $this->duplicateDocument($document, $company);

        $categories = $catRepo->findTreeByCompany($company);
        $counterparties = $cpRepo->findBy(['company' => $company]);
        $form = $this->createForm(DocumentType::class, $copy, [
            'categories' => $categories,
            'counterparties' => $counterparties,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($copy);
            $em->flush();

            $this->plRegisterUpdater->updateForDocument($copy);

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
                'isFallback' => null === $category && null !== $nature,
                'needsCategorization' => null === $category && null === $nature,
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

            $this->plRegisterUpdater->updateForDocument($document);

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
                'label' => PlNature::INCOME === $nature ? 'Доход' : 'Расход',
                'badgeClass' => PlNature::INCOME === $nature ? 'bg-green-lt text-green' : 'bg-red-lt text-red',
            ];
        }

        if ('MIXED' === $nature) {
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

    private function duplicateDocument(Document $source, Company $company): Document
    {
        $copy = new Document(Uuid::uuid4()->toString(), $company);
        $copy->setDate($source->getDate());
        $copy->setNumber($source->getNumber());
        $copy->setType($source->getType());
        $copy->setCounterparty($source->getCounterparty());
        $copy->setDescription($source->getDescription());

        foreach ($source->getOperations() as $operation) {
            $operationCopy = new DocumentOperation();
            $operationCopy->setPlCategory($operation->getPlCategory());
            $operationCopy->setAmount($operation->getAmount());
            $operationCopy->setCounterparty($operation->getCounterparty());
            $operationCopy->setComment($operation->getComment());
            $copy->addOperation($operationCopy);
        }

        return $copy;
    }
}
