<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\MoySklad\Application\Action\ManageMoySkladConnectionAction;
use App\MoySklad\Application\Command\ManageConnectionCommand;
use App\MoySklad\Exception\ConnectionOperationException;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
final class MoySkladConnectionMutationController extends AbstractController
{
    #[Route('/moy-sklad/connections/{id}/check', name: 'moysklad_connections_check', defaults: ['operation' => 'check'], methods: ['POST'])]
    #[Route('/moy-sklad/connections/{id}/replace-token', name: 'moysklad_connections_replace_token', defaults: ['operation' => 'replace-token'], methods: ['POST'])]
    #[Route('/moy-sklad/connections/{id}/disable', name: 'moysklad_connections_disable', defaults: ['operation' => 'disable'], methods: ['POST'])]
    #[Route('/moy-sklad/connections/{id}/enable', name: 'moysklad_connections_enable', defaults: ['operation' => 'enable'], methods: ['POST'])]
    #[Route('/moy-sklad/connections/{id}/delete', name: 'moysklad_connections_delete', defaults: ['operation' => 'delete'], methods: ['POST'])]
    public function __invoke(string $id, string $operation, Request $request, ActiveCompanyService $activeCompany, MoySkladConnectionWriteRepository $repository, ManageMoySkladConnectionAction $action): Response
    {
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        if (null === $repository->findByIdAndCompanyId($id, $companyId)) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('moysklad_'.$operation.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        try {
            $action(new ManageConnectionCommand($companyId, (string) $user->getId(), $operation, $id, token: $request->request->getString('token'), version: $request->request->getInt('version')));
            $this->addFlash('success', match ($operation) {
                'disable' => 'Подключение отключено. Токен в МойСклад не отозван.',
                'enable' => 'Подключение проверено и включено.',
                'delete' => 'Подключение и сохранённый токен удалены.',
                'replace-token' => 'Токен проверен и заменён.',
                default => 'Доступ к МойСклад подтверждён.',
            });
        } catch (ConnectionOperationException $e) {
            if ('not_found' === $e->reason) {
                throw $this->createNotFoundException();
            }
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('moysklad_connections_index');
    }
}
