<?php

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Form\Bank\BankConnectionType;
use App\Cash\Message\Import\BankImportMessage;
use App\Cash\Repository\Bank\BankConnectionRepository;
use App\Sahred\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/bank-connections')]
class BankConnectionController extends AbstractController
{
    public function __construct(
        private ActiveCompanyService $activeCompanyService,
        private MessageBusInterface $bus,
    ) {
    }

    #[Route('/', name: 'cash_bank_connection_index', methods: ['GET'])]
    public function index(BankConnectionRepository $repository): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $connections = $repository->findBy(['company' => $company]);

        return $this->render('cash/bank_connection/index.html.twig', [
            'connections' => $connections,
        ]);
    }

    #[Route('/new', name: 'cash_bank_connection_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $connection = new BankConnection(
            id: Uuid::uuid4()->toString(),
            company: $company,
            bankCode: 'alfa',
            apiKey: '',
            baseUrl: 'https://baas.alfabank.ru',
        );

        $form = $this->createForm(BankConnectionType::class, $connection, [
            'require_api_key' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($connection);
            $em->flush();

            return $this->redirectToRoute('cash_bank_connection_index');
        }

        return $this->render('cash/bank_connection/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'cash_bank_connection_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        string $id,
        BankConnectionRepository $repository,
        EntityManagerInterface $em,
    ): Response {
        $company = $this->activeCompanyService->getActiveCompany();
        $connection = $repository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        $existingApiKey = $connection->getApiKey();
        $form = $this->createForm(BankConnectionType::class, $connection, [
            'require_api_key' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submittedApiKey = (string) $form->get('apiKey')->getData();
            if ('' === trim($submittedApiKey)) {
                $connection->setApiKey($existingApiKey);
            } else {
                $connection->setApiKey($submittedApiKey);
            }

            $em->flush();

            return $this->redirectToRoute('cash_bank_connection_index');
        }

        return $this->render('cash/bank_connection/edit.html.twig', [
            'form' => $form->createView(),
            'connection' => $connection,
        ]);
    }

    #[Route('/{id}/delete', name: 'cash_bank_connection_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        string $id,
        BankConnectionRepository $repository,
        EntityManagerInterface $em,
    ): Response {
        $company = $this->activeCompanyService->getActiveCompany();
        $connection = $repository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$connection->getId(), (string) $request->request->get('_token'))) {
            $em->remove($connection);
            $em->flush();
        }

        return $this->redirectToRoute('cash_bank_connection_index');
    }

    #[Route('/{id}/enqueue-import', name: 'cash_bank_connection_enqueue_import', methods: ['POST'])]
    public function enqueueImport(
        Request $request,
        string $id,
        BankConnectionRepository $repository,
    ): Response {
        $company = $this->activeCompanyService->getActiveCompany();
        $connection = $repository->find($id);

        if (!$connection || $connection->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('enqueue_import_'.$connection->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->bus->dispatch(new BankImportMessage(
            (string) $company->getId(),
            $connection->getBankCode(),
        ));

        $this->addFlash('success', 'Импорт поставлен в очередь');

        return $this->redirectToRoute('cash_bank_connection_index');
    }
}
