<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Админский список (cross-company, вызывается только под ROLE_ADMIN).
     *
     * @param ?string $companyId      только пользователи этого кабинета (владелец или активный участник)
     * @param bool    $withoutCompany только пользователи без кабинета
     * @param bool    $ownersOnly     только владельцы кабинетов (companies.user_id)
     *
     * @return Pagerfanta<User>
     */
    public function getRegisteredUsers(int $page, int $limit = 20, ?string $companyId = null, bool $withoutCompany = false, bool $ownersOnly = false): Pagerfanta
    {
        $page = max(1, $page);

        $queryBuilder = $this->createQueryBuilder('user')
            ->orderBy('user.createdAt', \SortDirection::Descending);

        if (null !== $companyId) {
            $queryBuilder->andWhere(
                'EXISTS (SELECT 1 FROM '.Company::class.' filterOwnedCompany WHERE filterOwnedCompany.user = user AND filterOwnedCompany.id = :filterCompanyId)'
                .' OR EXISTS (SELECT 1 FROM '.CompanyMember::class.' filterMembership WHERE filterMembership.user = user AND IDENTITY(filterMembership.company) = :filterCompanyId AND filterMembership.status = :filterActiveStatus)',
            )
                ->setParameter('filterCompanyId', $companyId)
                ->setParameter('filterActiveStatus', CompanyMember::STATUS_ACTIVE);
        }

        if ($withoutCompany) {
            $queryBuilder->andWhere(
                'NOT EXISTS (SELECT 1 FROM '.Company::class.' filterOwnedNone WHERE filterOwnedNone.user = user)'
                .' AND NOT EXISTS (SELECT 1 FROM '.CompanyMember::class.' filterMembershipNone WHERE filterMembershipNone.user = user AND filterMembershipNone.status = :filterActiveStatus)',
            )
                ->setParameter('filterActiveStatus', CompanyMember::STATUS_ACTIVE);
        }

        if ($ownersOnly) {
            $queryBuilder->andWhere(
                'EXISTS (SELECT 1 FROM '.Company::class.' filterOwnedOwners WHERE filterOwnedOwners.user = user)',
            );
        }

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($limit);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
    }

    public function countRegisteredUsers(): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRegisteredUsersSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->andWhere('user.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function loadUserByIdentifier(string $identifier): ?User
    {
        $normalizedEmail = User::normalizeEmail($identifier);

        return $this->findOneBy(['email' => $normalizedEmail]);
    }

    public function findOneByIdAndCompanyId(string $userId, string $companyId): ?User
    {
        return $this->createQueryBuilder('user')
            ->leftJoin(
                Company::class,
                'ownedCompany',
                'ON',
                'ownedCompany.user = user AND ownedCompany.id = :companyId',
            )
            ->leftJoin(
                CompanyMember::class,
                'membership',
                'ON',
                'membership.user = user AND IDENTITY(membership.company) = :companyId AND membership.status = :activeStatus',
            )
            ->andWhere('user.id = :userId')
            ->andWhere('ownedCompany.id IS NOT NULL OR membership.id IS NOT NULL')
            ->setParameter('userId', $userId)
            ->setParameter('companyId', $companyId)
            ->setParameter('activeStatus', CompanyMember::STATUS_ACTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', \SortDirection::Ascending)
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
