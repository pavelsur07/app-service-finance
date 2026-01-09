<?php

// src/Command/TestOzonApiCommand.php

namespace App\Command;

use App\Marketplace\Ozon\Adapter\OzonApiClient;
use App\Repository\CompanyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'test:ozon:api')]
class TestOzonApiCommand extends Command
{
    public function __construct(
        private OzonApiClient $client,
        private CompanyRepository $companyRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $this->companyRepo->findOneBy([]); // Возьми нужную компанию
        if (!$company) {
            $output->writeln('Нет компании');

            return Command::FAILURE;
        }

        $products = $this->client->getAllProducts(
            $company->getOzonClientId(),
            $company->getOzonApiKey()
        );

        $output->writeln('Всего товаров: '.count($products));
        foreach ($products as $p) {
            $output->writeln($p['sku'].' | '.$p['name']);
        }

        return Command::SUCCESS;
    }
}
