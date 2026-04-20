<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace-ads/admin/debug', name: 'marketplace_ads_admin_debug', methods: ['GET'])]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OzonDebugPageController extends AbstractController
{
    public function __construct(
        private readonly ActiveOzonPerformanceConnectionsQuery $connectionsQuery,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(): Response
    {
        $companyIds = $this->connectionsQuery->getCompanyIds();

        $companies = [];
        if ([] !== $companyIds) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id::text AS id, name FROM company WHERE id IN (:ids) ORDER BY name ASC',
                ['ids' => $companyIds],
                ['ids' => Connection::PARAM_STR_ARRAY],
            );
            foreach ($rows as $row) {
                $companies[] = [
                    'id' => (string) $row['id'],
                    'name' => (string) $row['name'],
                ];
            }
        }

        return $this->render('marketplace_ads/admin_debug.html.twig', [
            'companies' => $companies,
        ]);
    }
}
