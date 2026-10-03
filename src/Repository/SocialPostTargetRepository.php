<?php

namespace Base\Social\Repository;

use Base\Social\Entity\SocialPostTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialPostTarget> */
class SocialPostTargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPostTarget::class);
    }

    /** The last target that left for a network, whatever became of it: the dashboard's line. */
    public function findLastFor(string $platform): ?SocialPostTarget
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.platform = :platform')->setParameter('platform', $platform)
            ->andWhere('t.enabled = true')
            ->andWhere('t.updatedAt IS NOT NULL')
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
