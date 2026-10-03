<?php

namespace Base\Social\Service;

use Base\Social\Entity\SocialMedia;
use Base\Social\Repository\SocialMediaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnipost\Exception\InvalidConfigException;
use Omnipost\FeedInterface;
use Omnipost\Model\FeedItem;
use Omnipost\Model\PostKind;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The account's last posts read and kept (Entity\SocialMedia), for the wall:
 * new ones added, known ones updated (caption, likes, the networks' fresh
 * addresses), the thumbnail copied once into the storage.
 *
 * The copy is downloaded here rather than left to the Uploader's fetch: a
 * picture that does not answer, or answers with something else, costs the
 * row its thumbnail and nothing more - the Uploader would refuse the whole
 * flush.
 */
class FeedSync
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly SocialMediaRepository $media,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger = new NullLogger(),
        #[Autowire('%social.feed.limit%')] private readonly int $limit = 24,
        #[Autowire('%social.feed.cache_thumbnails%')] private readonly bool $cacheThumbnails = true,
    ) {
    }

    /**
     * @return int the posts read
     *
     * @throws \Omnipost\Exception\OmnipostException the network refused, or the provider has no feed
     */
    public function sync(string $provider, ?int $limit = null): int
    {
        $feed = $this->accounts->provider($provider);
        if (!$feed instanceof FeedInterface) {
            throw new InvalidConfigException(\sprintf('The "%s" provider has no feed.', $provider));
        }

        $this->accounts->saveAccount($provider, $feed->account());
        $items = $feed->feed(null, $limit ?? $this->limit)->items;
        foreach ($items as $item) {
            $media = $this->media->findOneRemote($provider, $item->id) ?? new SocialMedia($provider, $item->id);
            $this->fill($media, $item);
            $this->entityManager->persist($media);
        }
        $this->entityManager->flush();

        return \count($items);
    }

    private function fill(SocialMedia $media, FeedItem $item): void
    {
        $first = $item->children[0] ?? null;
        $video = \in_array($item->kind, [PostKind::REEL, PostKind::VIDEO], true);
        $picture = $video ? ($item->thumbnailUrl ?? $first?->thumbnailUrl) : ($item->url ?? $item->thumbnailUrl ?? $first?->url ?? $first?->thumbnailUrl);

        $media->setKind($item->kind)
            ->setPermalink($item->permalink)
            ->setCaption($item->caption)
            ->setPublishedAt($item->publishedAt ? \DateTime::createFromImmutable($item->publishedAt) : $media->getPublishedAt())
            ->setMediaUrl($picture)
            ->setVideoUrl($video ? $item->url : null)
            ->setLikes($item->likes)
            ->setComments($item->comments)
            ->touch();

        if ($this->cacheThumbnails && !$media->hasThumbnail() && $picture && ($file = $this->download($picture))) {
            $media->setThumbnail($file);
        }
    }

    private function download(string $url): ?File
    {
        try {
            $response = $this->http->request('GET', $url, ['timeout' => 20, 'max_duration' => 60]);
            $type = (string) ($response->getHeaders()['content-type'][0] ?? '');
            if (!str_starts_with($type, 'image/')) {
                return null;
            }
            $path = tempnam(sys_get_temp_dir(), 'social');
            file_put_contents($path, $response->getContent());

            return new File($path);
        } catch (\Throwable $e) {
            $this->logger->notice('social: thumbnail {url} not copied: {error}', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
