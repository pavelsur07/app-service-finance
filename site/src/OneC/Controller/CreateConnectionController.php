<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\OneC\Application\CreateConnectionAction;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::FINANCE_READ)]
final class CreateConnectionController extends AbstractController
{
    #[Route('/onec/connections/new', name: 'onec_connection_create', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, ActiveCompanyService $activeCompany, CreateConnectionAction $create, CsrfTokenManagerInterface $csrf): Response
    {
        $companyId = (string) $activeCompany->getActiveCompany()->getId();
        $requestId = $request->isMethod('POST') ? $request->request->getString('request_id') : Uuid::uuid7()->toString();
        $tokenId = 'onec_create_'.$companyId.'_'.$requestId;
        $secret = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
            if (!Uuid::isValid($requestId) || !$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $name = trim($request->request->getString('name'));
            if ('' === $name || mb_strlen($name) > 255 || str_contains($name, "\0")) {
                $status = 422;
            } else {
                try {
                    $secret = $create($requestId, $companyId, (string) $user->getId(), $name);
                } catch (UniqueConstraintViolationException) {
                    throw $this->createAccessDeniedException('Эта форма уже отправлена.');
                }
                $csrf->removeToken($tokenId);
                $status = 201;
            }
        }

        return $this->render('onec/create.html.twig', ['requestId' => $requestId, 'tokenId' => $tokenId, 'secret' => $secret, 'invalidName' => 422 === $status], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}
