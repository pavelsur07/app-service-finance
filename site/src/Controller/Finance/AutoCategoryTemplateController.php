<?php

namespace App\Controller\Finance;

use App\Entity\AutoCategoryTemplate;
use App\Entity\CashTransaction;
use App\Enum\AutoTemplateScope;
use App\Enum\AutoTemplateDirection;
use App\Enum\MatchLogic;
use App\Form\AutoCategoryTemplateType;
use App\Repository\AutoCategoryTemplateRepository;
use App\Repository\CashTransactionRepository;
use App\Service\ActiveCompanyService;
use App\Service\AutoCategory\ConditionEvaluatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ramsey\Uuid\Uuid;

#[Route('/finance/auto-templates')]
#[IsGranted('ROLE_USER')]
class AutoCategoryTemplateController extends AbstractController
{
    public function __construct(private ActiveCompanyService $companyService)
    {
    }

    #[Route('/', name: 'auto_template_index', methods: ['GET'])]
    public function index(AutoCategoryTemplateRepository $repo): Response
    {
        $company = $this->companyService->getActiveCompany();
        $templates = $repo->findBy([
            'company' => $company,
            'scope' => AutoTemplateScope::CASHFLOW,
        ], ['priority' => 'ASC']);

        return $this->render('auto_template/index.html.twig', [
            'templates' => $templates,
        ]);
    }

    #[Route('/new', name: 'auto_template_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $company = $this->companyService->getActiveCompany();
        $template = new AutoCategoryTemplate(Uuid::uuid4()->toString(), $company);
        $template->setScope(AutoTemplateScope::CASHFLOW);

        $form = $this->createForm(AutoCategoryTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($template);
            $em->flush();
            return $this->redirectToRoute('auto_template_index');
        }

        return $this->render('auto_template/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'auto_template_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, AutoCategoryTemplate $template, EntityManagerInterface $em): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($template->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(AutoCategoryTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            return $this->redirectToRoute('auto_template_index');
        }

        return $this->render('auto_template/form.html.twig', [
            'form' => $form->createView(),
            'template' => $template,
        ]);
    }

    #[Route('/{id}/toggle', name: 'auto_template_toggle', methods: ['POST'])]
    public function toggle(Request $request, AutoCategoryTemplate $template, EntityManagerInterface $em): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($template->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $template->setIsActive(!$template->isActive());
        $em->flush();
        $referer = $request->headers->get('referer');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('auto_template_index');
    }

    #[Route('/{id}/delete', name: 'auto_template_delete', methods: ['POST'])]
    public function delete(AutoCategoryTemplate $template, EntityManagerInterface $em): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($template->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $em->remove($template);
        $em->flush();
        return $this->redirectToRoute('auto_template_index');
    }

    #[Route('/{id}/test', name: 'auto_template_test', methods: ['GET', 'POST'])]
    public function test(
        Request $request,
        AutoCategoryTemplate $template,
        CashTransactionRepository $txRepo,
        ConditionEvaluatorInterface $evaluator
    ): Response {
        $company = $this->companyService->getActiveCompany();
        if ($template->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $operations = $txRepo->findBy(
            ['company' => $company],
            ['occurredAt' => 'DESC'],
            50
        );

        $result = null;
        $selected = null;

        if ($request->isMethod('POST')) {
            $txId = $request->request->get('tx');
            /** @var CashTransaction|null $tx */
            $tx = $txRepo->find($txId);
            if ($tx && $tx->getCompany() === $company) {
                $selected = $tx;
                $operation = [
                    'plat_inn' => null,
                    'pol_inn' => null,
                    'description' => $tx->getDescription() ?? '',
                    'amount' => $tx->getAmount(),
                    'counterparty_name_raw' => $tx->getCounterparty()?->getName(),
                    'payer_account' => null,
                    'payee_account' => null,
                    'payer_bic' => null,
                    'payee_bank' => null,
                    'doc_number' => $tx->getExternalId(),
                    'date' => $tx->getOccurredAt(),
                    'money_account' => $tx->getMoneyAccount()->getId(),
                    'counterparty_id' => $tx->getCounterparty()?->getId(),
                    'counterparty_type' => $tx->getCounterparty()?->getType()->value ?? null,
                ];

                $condResults = [];
                foreach ($template->getConditions() as $cond) {
                    $condResults[] = [
                        'field' => $cond->getField()->value,
                        'operator' => $cond->getOperator()->value,
                        'value' => $cond->getValue(),
                        'result' => $evaluator->isConditionMatched($operation, $cond),
                    ];
                }
                $matches = $template->getMatchLogic() === MatchLogic::ALL
                    ? !in_array(false, array_column($condResults, 'result'), true)
                    : in_array(true, array_column($condResults, 'result'), true);

                $result = [
                    'conditions' => $condResults,
                    'category' => $matches ? $template->getTargetCategory() : null,
                ];
            }
        }

        return $this->render('auto_template/test.html.twig', [
            'template' => $template,
            'operations' => $operations,
            'selected' => $selected,
            'result' => $result,
        ]);
    }
}

