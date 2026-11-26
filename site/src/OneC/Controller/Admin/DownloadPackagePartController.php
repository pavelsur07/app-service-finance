<?php

declare(strict_types=1);

namespace App\OneC\Controller\Admin;

use App\OneC\Controller\DownloadPackagePartController as CabinetDownload;
use App\OneC\Infrastructure\Repository\OneCConnectionRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Сырьё любой компании для разбора обращений: firewall admin, ROLE_ADMIN. */
#[IsGranted('ROLE_ADMIN')]
final class DownloadPackagePartController extends AbstractController
{
    #[Route('/admin/onec/packages/{id}/raw', name: 'admin_onec_package_raw', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id, OneCConnectionRepository $connections, ObjectStorageInterface $storage): Response
    {
        $part = $connections->findRawPartAnyCompany($id);
        if (null === $part) {
            throw $this->createNotFoundException();
        }

        return CabinetDownload::attachment($storage, $part);
    }
}
