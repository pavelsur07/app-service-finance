<?php

namespace App\Service\Ozon;

use App\Api\Ozon\OzonApiClient;
use App\Entity\Company;
use App\Entity\Ozon\OzonProduct;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

readonly class OzonProductSyncService
{
    public function __construct(
        private OzonApiClient          $client,
        private EntityManagerInterface $em
    ) {}

    public function sync(Company $company): void
    {
        $products = $this->client->getAllProducts(
            $company->getOzonSellerId(),
            $company->getOzonApiKey()
        );

        foreach ($products as $data) {

            if ($data['archived']) {
                continue;
            }

            $product = $this->em->getRepository(OzonProduct::class)->findOneBy([
                'ozonSku' => $data['sku'],
                'company' => $company,
            ]) ?? new OzonProduct(id: Uuid::uuid4()->toString(), company: $company);

            $product->setOzonSku($data['sku']);
            $product->setManufacturerSku($data['manufacturerSku'] ?? '');
            $product->setName($data['name']);
            $product->setPrice($data['price']);
            $product->setImageUrl($data['image_url']);
            $product->setArchived(false);
            $product->setCompany($company);

            $this->em->persist($product);
        }

        $this->em->flush();
    }
}
