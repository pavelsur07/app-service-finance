<?php

namespace App\Company\Controller;

use App\Balance\Service\BalanceStructureSeeder;
use App\Company\Form\CompanyType;
use App\Entity\Company;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    #[Route('/{id}/check-wb-key', name: 'company_check_wb_key', methods: ['POST'])]
    public function checkWildberriesKey(
        Request $request,
        Company $company,
        HttpClientInterface $httpClient,
    ): Response {
        // CSRF-проверка для защиты действия
        if (!$this->isCsrfTokenValid('company_check_wb_key'.$company->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF-токен при проверке ключа Wildberries.');

            return $this->redirectToRoute('company_edit', ['id' => $company->getId()]);
        }

        $token = trim((string) $company->getWildberriesApiKey());

        if ('' === $token) {
            $this->addFlash('danger', 'WB API ключ не заполнен. Сначала укажите ключ и сохраните компанию.');

            return $this->redirectToRoute('company_edit', ['id' => $company->getId()]);
        }

        try {
            // Официальный метод проверки токена из документации WB:
            // GET https://common-api.wildberries.ru/ping
            $response = $httpClient->request('GET', 'https://common-api.wildberries.ru/ping', [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                ],
                'timeout' => 5.0,
            ]);

            $statusCode = $response->getStatusCode();

            if (200 === $statusCode) {
                $this->addFlash('success', 'WB API ключ активен. Соединение с WB API установлено успешно.');
            } elseif (401 === $statusCode) {
                $this->addFlash('danger', 'WB API ключ неверный или истёк (401 Unauthorized). Проверьте токен в личном кабинете WB.');
            } elseif (403 === $statusCode) {
                $this->addFlash('danger', 'WB API ключ не даёт доступ к этому сервису (403 Forbidden). Проверьте категории токена в личном кабинете WB.');
            } else {
                $this->addFlash('warning', sprintf(
                    'Не удалось однозначно проверить WB API ключ. Код ответа: %d.',
                    $statusCode
                ));
            }
        } catch (\Throwable $e) {
            // Любые сетевые/транспортные ошибки не считаем автоматом «битым токеном»
            $this->addFlash('warning', 'Ошибка при проверке WB API ключа: '.$e->getMessage());
        }

        return $this->redirectToRoute('company_edit', ['id' => $company->getId()]);
    }
}
