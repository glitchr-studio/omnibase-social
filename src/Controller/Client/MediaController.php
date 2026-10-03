<?php

namespace Base\Social\Controller\Client;

use Base\Social\Repository\SocialPostTargetRepository;
use Base\Social\Service\MediaToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A rendered film (or its cover) at the public address the network fetches
 * it from (Service\MediaUrls): the signature must name this target and this
 * file, and the publication must be on its way - or have ended less than
 * social.media_url_ttl seconds ago. Ranges are answered (BinaryFileResponse),
 * as the networks' fetchers ask for them.
 */
class MediaController extends AbstractController
{
    public function __construct(
        private readonly SocialPostTargetRepository $targets,
        private readonly MediaToken $tokens,
        #[Autowire('%social.media_url_ttl%')] private readonly int $ttl = 86400,
    ) {
    }

    #[Route('/social/media/{target}/{token}.{ext}', name: 'social_media', requirements: ['target' => '\d+', 'token' => '[a-f0-9]{32}', 'ext' => '[a-z0-9]{2,5}'], methods: ['GET', 'HEAD'])]
    public function media(int $target, string $token, string $ext): BinaryFileResponse
    {
        $found = $this->targets->find($target) ?? throw $this->createNotFoundException();
        $path = null;
        foreach ([$found->getRenderedPath(), $found->getCoverPath()] as $candidate) {
            if (null !== $candidate && $this->tokens->verify($target, $candidate, $token)) {
                $path = $candidate;
                break;
            }
        }
        // One answer for a wrong signature, a closed one and a missing file: nothing to learn here.
        if (null === $path || !$found->isMediaOpen($this->ttl) || !is_file($path) || strtolower(pathinfo($path, \PATHINFO_EXTENSION)) !== $ext) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path, 200, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], false, ResponseHeaderBag::DISPOSITION_INLINE, true, true);
        $response->headers->set('Content-Type', match ($ext) {
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            default => 'application/octet-stream',
        });

        return $response;
    }
}
