<?php

namespace Base\Social\Repository;

use Base\Social\Entity\SocialPostTarget;
use Base\Social\Entity\Template;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Template> */
class TemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Template::class);
    }

    /** The site's own template, if it has one. */
    public function findDefault(): ?Template
    {
        return $this->findOneBy(['default' => true, 'platform' => null], ['id' => 'ASC'])
            ?? $this->findOneBy(['default' => true], ['id' => 'ASC']);
    }

    /** The template kept for one network alone. */
    public function findForPlatform(string $platform): ?Template
    {
        return $this->findOneBy(['platform' => $platform], ['default' => 'DESC', 'id' => 'ASC']);
    }

    /**
     * The template a target is rendered with: its own, else its post's, else
     * its network's, else the site's. Null: the post goes out untemplated
     * (it says so, or the site has no template yet).
     */
    public function resolve(SocialPostTarget $target): ?Template
    {
        $post = $target->getPost();
        if (null === $post || !$post->isUseTemplate()) {
            return null;
        }

        return $target->getTemplate()
            ?? $post->getTemplate()
            ?? $this->findForPlatform((string) $target->getPlatform())
            ?? $this->findDefault();
    }
}
