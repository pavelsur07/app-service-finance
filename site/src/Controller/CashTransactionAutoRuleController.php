<?php

namespace App\Controller;

use App\Entity\CashTransactionAutoRule;
use App\Enum\CashTransactionAutoRuleAction;
use App\Enum\CashTransactionAutoRuleOperationType;
use App\Form\CashTransactionAutoRuleType;
use App\Repository\CashTransactionAutoRuleRepository;
use App\Repository\CashflowCategoryRepository;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cash-transaction-auto-rules')]
class CashTransactionAutoRuleController extends AbstractController
{
    #[Route('/', name: 'cash_transaction_auto_rule_index', methods: ['GET'])]
    public function index(CashTransactionAutoRuleRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findByCompany($company);

        return $this->render('cash_transaction_auto_rule/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'cash_transaction_auto_rule_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo
    ): Response {
        $company = $companyService->getActiveCompany();
        $categories = $categoryRepo->findTreeByCompany($company);

        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            '',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY
        );

        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, ['categories' => $categories]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rule);
            $em->flush();
            return $this->redirectToRoute('cash_transaction_auto_rule_index');
        }

        return $this->render('cash_transaction_auto_rule/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'cash_transaction_auto_rule_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $id,
        Request $request,
        CashTransactionAutoRuleRepository $repo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        CashflowCategoryRepository $categoryRepo
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $repo->find($id);
        if (!$rule || $rule->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $categories = $categoryRepo->findTreeByCompany($company);
        $form = $this->createForm(CashTransactionAutoRuleType::class, $rule, ['categories' => $categories]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            return $this->redirectToRoute('cash_transaction_auto_rule_index');
        }

        return $this->render('cash_transaction_auto_rule/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $rule,
        ]);
    }

    #[Route('/{id}/delete', name: 'cash_transaction_auto_rule_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        CashTransactionAutoRuleRepository $repo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService
    ): Response {
        $company = $companyService->getActiveCompany();
        $rule = $repo->find($id);
        if (!$rule || $rule->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$rule->getId(), $request->request->get('_token'))) {
            $em->remove($rule);
            $em->flush();
        }

        return $this->redirectToRoute('cash_transaction_auto_rule_index');
    }
}
