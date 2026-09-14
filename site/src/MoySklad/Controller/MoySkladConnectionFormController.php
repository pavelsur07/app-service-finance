<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\ManageMoySkladConnectionAction;
use App\MoySklad\Application\Command\ManageConnectionCommand;
use App\MoySklad\Exception\ConnectionOperationException;
use App\MoySklad\Form\MoySkladConnectionType;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class MoySkladConnectionFormController extends AbstractController
{
    #[Route('/moy-sklad/connections/create', name: 'moysklad_connections_create', methods: ['GET', 'POST'])]
    #[Route('/moy-sklad/connections/{id}/edit', name: 'moysklad_connections_edit', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $repository, ManageMoySkladConnectionAction $action, ?string $id = null): Response
    {
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $connection = null !== $id ? $repository->findByIdAndCompanyId($id, $companyId) : null;
        if (null !== $id && null === $connection) {
            throw $this->createNotFoundException();
        }
        $isEdit = null !== $connection;
        $data = $isEdit ? ['name' => $connection->getName(), 'version' => (string) $connection->getVersion()] : [];
        $form = $this->createForm(MoySkladConnectionType::class, $data, ['is_edit' => $isEdit]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name:string,token?:string,version?:string} $values */
            $values = $form->getData();
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }
            try {
                $action(new ManageConnectionCommand($companyId, (string) $user->getId(), $isEdit ? 'edit' : 'create', $id, $values['name'], $values['token'] ?? '', (int) ($values['version'] ?? 0)));
                $this->addFlash('success', $isEdit ? 'Название подключения сохранено.' : 'Подключение к МойСклад создано.');

                return $this->redirectToRoute('moysklad_connections_index');
            } catch (ConnectionOperationException $e) {
                if ('not_found' === $e->reason) {
                    throw $this->createNotFoundException();
                }
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('moy_sklad/connections/form.html.twig', ['form' => $form->createView(), 'isEdit' => $isEdit, 'connection' => $connection], new Response(status: $form->isSubmitted() ? 422 : 200));
    }
}
