<?php

namespace App\Company\Controller;

use App\Balance\Service\BalanceStructureSeeder;
use App\Company\Entity\Company;
use App\Company\Form\CompanyType;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/company')]
class CompanyController extends AbstractController
{
    #[Route('/', name: 'company_index', methods: ['GET'])]
    public function index(CompanyRepository $companyRepository): Response
    {
        // Показываем только свои компании
        $companies = $companyRepository->findByUser($this->getUser());

        return $this->render('company/index.html.twig', [
            'companies' => $companies,
        ]);
    }

    #[Route('/active', name: 'company_set_active', methods: ['POST'])]
    public function setActive(Request $request, CompanyRepository $companyRepository): Response
    {
        $id = $request->request->get('company_id');
        $company = $companyRepository->find($id);

        if (!$company || $company->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        $request->getSession()->set('active_company_id', $company->getId());

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_home_index'));
    }

    #[Route('/new', name: 'company_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, BalanceStructureSeeder $balanceSeeder): Response
    {
        $company = new Company(id: Uuid::uuid4()->toString(), user: $this->getUser());
        $company->setUser($this->getUser()); // Автоматически проставляем владельца

        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($company);
            $balanceSeeder->seedDefaultIfEmpty($company);
            $em->flush();

            return $this->redirectToRoute('company_index');
        }

        return $this->render('company/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'company_show', methods: ['GET'])]
    public function show(string $id, Company $company): Response
    {
        // Можно добавить проверку владельца!
        return $this->render('company/show.html.twig', [
            'company' => $company,
        ]);
    }

    #[Route('/{id}/edit', name: 'company_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, Company $company, EntityManagerInterface $em): Response
    {
        // Можно добавить проверку владельца!
        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            return $this->redirectToRoute('company_index');
        }

        return $this->render('company/edit.html.twig', [
            'form' => $form->createView(),
            'company' => $company,
        ]);
    }

    #[Route('/{id}/delete', name: 'company_delete', methods: ['POST'])]
    public function delete(Request $request, Company $company, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$company->getId(), $request->request->get('_token'))) {
            $em->remove($company);
            $em->flush();
        }

        return $this->redirectToRoute('company_index');
    }
}
