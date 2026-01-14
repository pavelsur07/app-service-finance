<?php

namespace App\Cash\Repository\Bank;

use App\Cash\Entity\Bank\BankImportCursor;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankImportCursor>
 */
class BankImportCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankImportCursor::class);
    }

    public function getOrCreate(Company $company, string $bankCode, string $accountNumber): BankImportCursor
    {
        $cursor = $this->findOneBy([
            'company' => $company,
            'bankCode' => $bankCode,
            'accountNumber' => $accountNumber,
        ]);

        if ($cursor instanceof BankImportCursor) {
            return $cursor;
        }

        $cursor = new BankImportCursor($company, $bankCode, $accountNumber);
        $this->_em->persist($cursor);
        $this->_em->flush();

        return $cursor;
    }

    public function save(BankImportCursor $cursor): void
    {
        $this->_em->persist($cursor);
        $this->_em->flush();
    }
}
