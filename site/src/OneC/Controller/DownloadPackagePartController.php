<?php

declare(strict_types=1);

namespace App\OneC\Controller;

use App\Company\Security\ModuleAccess;
use App\OneC\Infrastructure\Repository\OneCConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Сырая часть пакета байт-в-байт, как её прислала обработка: только подключения активной компании. */
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class DownloadPackagePartController extends AbstractController
{
    #[Route('/onec/packages/{id}/raw', name: 'onec_package_raw', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id, ActiveCompanyService $activeCompany, OneCConnectionRepository $connections, ObjectStorageInterface $storage): Response
    {
        $part = $connections->findRawPart($id, (string) $activeCompany->getActiveCompany()->getId());
        if (null === $part) {
            throw $this->createNotFoundException();
        }

        return self::attachment($storage, $part);
    }

    /** @param array{path: string, filename: string} $part */
    public static function attachment(ObjectStorageInterface $storage, array $part): Response
    {
        return new Response((string) gzdecode($storage->read($part['path'])), headers: [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $part['filename']),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
