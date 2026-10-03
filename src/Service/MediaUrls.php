<?php

namespace Base\Social\Service;

use Base\Social\Entity\SocialPostTarget;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public address of a rendered file, for the network to fetch it:
 * Instagram does not take an upload, it comes for the film at an address
 * it is given. Signed (MediaToken), it opens on that one file only, and
 * only while the publication is on its way (Controller\Client\MediaController).
 *
 * Absolute: from a Messenger worker there is no request to take the host
 * from, so framework.router.default_uri must name the site.
 */
final class MediaUrls
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly MediaToken $tokens,
    ) {
    }

    /** The film's address - or, $cover, its still's. */
    public function for(SocialPostTarget $target, bool $cover = false): string
    {
        $path = ($cover ? $target->getCoverPath() : $target->getRenderedPath()) ?? throw new \LogicException(\sprintf('The %s target has no %s yet.', $target->getPlatform(), $cover ? 'cover' : 'rendering'));
        $id = (int) $target->getId();

        return $this->urls->generate('social_media', [
            'target' => $id,
            'token' => $this->tokens->sign($id, $path),
            'ext' => strtolower(pathinfo($path, \PATHINFO_EXTENSION)) ?: 'bin',
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** As SocialPost::toPost() takes it. */
    public function callable(): \Closure
    {
        return fn (SocialPostTarget $target, bool $cover = false): string => $this->for($target, $cover);
    }
}
