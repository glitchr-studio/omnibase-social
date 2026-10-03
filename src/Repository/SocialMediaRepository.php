<?php

namespace Base\Social\Repository;

use Base\Social\Entity\SocialMedia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialMedia> */
class SocialMediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialMedia::class);
    }

    /**
     * The wall: the account's last posts, the hidden ones left out - of one
     * provider, or of all.
     *
     * @return list<SocialMedia>
     */
    public function findWall(int $limit = 12, ?string $provider = null): array
    {
        $query = $this->createQueryBuilder('m')
            ->andWhere('m.hidden = false')
            ->orderBy('m.publishedAt', 'DESC')
            ->setMaxResults($limit);
        if (null !== $provider) {
            $query->andWhere('m.provider = :provider')->setParameter('provider', $provider);
        }

        return $query->getQuery()->getResult();
    }

    public function findOneRemote(string $provider, string $remoteId): ?SocialMedia
    {
        return $this->findOneBy(['provider' => $provider, 'remoteId' => $remoteId]);
    }

    /** When the provider's feed was last read; null: never. */
    public function lastSync(string $provider): ?\DateTimeInterface
    {
        $at = $this->createQueryBuilder('m')->select('MAX(m.fetchedAt)')
            ->andWhere('m.provider = :provider')->setParameter('provider', $provider)
            ->getQuery()->getSingleScalarResult();

        return $at ? new \DateTimeImmutable((string) $at) : null;
    }

    public function countFor(string $provider): int
    {
        return $this->count(['provider' => $provider]);
    }
}
