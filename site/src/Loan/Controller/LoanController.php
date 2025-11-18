<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use App\Loan\Entity\Loan;
use App\Loan\Form\LoanType;
use App\Loan\Repository\LoanRepository;
use App\Service\ActiveCompanyService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/loans')]
class LoanController extends AbstractController
{
    #[Route('', name: 'loan_index', methods: ['GET'])]
    public function index(LoanRepository $loanRepository, ActiveCompanyService $activeCompanyService): Response
    {
        $company = $activeCompanyService->getActiveCompany();
        $loans = $loanRepository->findBy([
            'company' => $company,
        ], [
            'startDate' => 'DESC',
            'createdAt' => 'DESC',
        ]);

        return $this->render('loan/index.html.twig', [
            'loans' => $loans,
        ]);
    }

    #[Route('/create', name: 'loan_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, ActiveCompanyService $activeCompanyService): Response
    {
        $company = $activeCompanyService->getActiveCompany();
        $loan = new Loan($company, '', '0.00', new DateTimeImmutable());
        $form = $this->createForm(LoanType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $loan->setUpdatedAt(new DateTimeImmutable());
            $entityManager->persist($loan);
            $entityManager->flush();

            return $this->redirectToRoute('loan_index');
        }

        return $this->render('loan/form.html.twig', [
            'form' => $form->createView(),
            'loan' => $loan,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'loan_edit', methods: ['GET', 'POST'])]
    public function edit(Loan $loan, Request $request, EntityManagerInterface $entityManager, ActiveCompanyService $activeCompanyService): Response
    {
        $company = $activeCompanyService->getActiveCompany();
        if ($loan->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(LoanType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $loan->setUpdatedAt(new DateTimeImmutable());
            $entityManager->flush();

            return $this->redirectToRoute('loan_index');
        }

        return $this->render('loan/form.html.twig', [
            'form' => $form->createView(),
            'loan' => $loan,
            'is_edit' => true,
        ]);
    }
}
