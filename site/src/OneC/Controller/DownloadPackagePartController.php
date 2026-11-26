<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Entity\User;
use App\OneC\Infrastructure\Query\ConnectionQuery;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Сырая часть пакета байт-в-байт, как её прислала обработка: владельцу
 * подключения в кабинете, администратору — в админке (firewall admin, ROLE_ADMIN).
 */
#[IsGranted('ROLE_USER')]
final class DownloadPackagePartController extends AbstractController
{
    #[Route('/onec/packages/{id}/raw', name: 'onec_package_raw', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[Route('/admin/onec/packages/{id}/raw', name: 'admin_onec_package_raw', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id, #[CurrentUser] User $user, ConnectionQuery $connections, ObjectStorageInterface $storage): Response
    {
        $part = $connections->rawPart($id, $this->isGranted('ROLE_ADMIN') ? null : (string) $user->getId());
        if (null === $part) {
            throw $this->createNotFoundException();
        }

        return new Response((string) gzdecode($storage->read($part['path'])), headers: [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $part['filename']),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
