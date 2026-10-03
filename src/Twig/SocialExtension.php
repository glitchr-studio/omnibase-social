<?php

namespace Base\Social\Twig;

use Base\Social\Repository\SocialMediaRepository;
use Base\Social\Service\Accounts;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What a host's own pages ask: the wall of the account's last posts, any
 * public post embedded (an Instagram post, a YouTube film), whether an
 * account is connected.
 */
final class SocialExtension extends AbstractExtension
{
    public function __construct(
        private readonly SocialMediaRepository $media,
        private readonly Accounts $accounts,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('social_wall', $this->wall(...), ['needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('social_embed', $this->embed(...), ['needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('social_connected', fn (string $provider): bool => $this->accounts->has($provider) && $this->accounts->connected($provider)),
        ];
    }

    public function wall(Environment $twig, int $limit = 12, ?string $provider = null): string
    {
        return $twig->render('@Social/client/_wall.html.twig', ['items' => $this->media->findWall($limit, $provider), 'provider' => $provider]);
    }

    /** $consent: loaded at once, the visitor having said yes to the network's cookies already. */
    public function embed(Environment $twig, string $url, bool $consent = false): string
    {
        return $twig->render('@Social/client/_embed.html.twig', self::recognize($url) + ['url' => $url, 'consent' => $consent]);
    }

    /**
     * What a public address is: an Instagram post (its permalink, cleaned), a
     * YouTube film (its id), or neither.
     *
     * @return array{network: ?string, permalink: ?string, id: ?string}
     */
    public static function recognize(string $url): array
    {
        if (preg_match('#^https?://(?:www\.)?instagram\.com/(?:[\w.]+/)?(p|reel|reels|tv)/([\w-]+)#i', $url, $m)) {
            $kind = 'reels' === strtolower($m[1]) ? 'reel' : strtolower($m[1]);

            return ['network' => 'instagram', 'permalink' => "https://www.instagram.com/$kind/{$m[2]}/", 'id' => $m[2]];
        }
        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([\w-]{11})#i', $url, $m)) {
            return ['network' => 'youtube', 'permalink' => 'https://www.youtube.com/watch?v='.$m[1], 'id' => $m[1]];
        }

        return ['network' => null, 'permalink' => null, 'id' => null];
    }
}
