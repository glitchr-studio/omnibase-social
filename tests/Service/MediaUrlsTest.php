<?php

namespace Base\Social\Tests\Service;

use Base\Social\Entity\SocialPostTarget;
use Base\Social\Model\Rendering;
use Base\Social\Service\MediaToken;
use Base\Social\Service\MediaUrls;
use Omnipost\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class MediaUrlsTest extends TestCase
{
    private const SECRET = 'a-fixed-kernel-secret';

    public function testTheTokenRoundTrips(): void
    {
        $tokens = new MediaToken(self::SECRET);
        $token = $tokens->sign(7, '/var/storage/social/3/instagram.mp4');

        self::assertSame(MediaToken::LENGTH, \strlen($token));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        self::assertSame($token, (new MediaToken(self::SECRET))->sign(7, '/var/storage/social/3/instagram.mp4'));
        self::assertTrue($tokens->verify(7, '/var/storage/social/3/instagram.mp4', $token));
        // Another target, another file, another secret, a token cut short: refused.
        self::assertFalse($tokens->verify(8, '/var/storage/social/3/instagram.mp4', $token));
        self::assertFalse($tokens->verify(7, '/var/storage/social/3/youtube.mp4', $token));
        self::assertFalse((new MediaToken('another-secret'))->verify(7, '/var/storage/social/3/instagram.mp4', $token));
        self::assertFalse($tokens->verify(7, '/var/storage/social/3/instagram.mp4', substr($token, 0, 16)));
    }

    public function testTheUrlCarriesTheTargetTheTokenAndTheExtension(): void
    {
        $generator = new class implements UrlGeneratorInterface {
            public array $calls = [];

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $this->calls[] = [$name, $parameters, $referenceType];

                return \sprintf('https://site.example/social/media/%d/%s.%s', $parameters['target'], $parameters['token'], $parameters['ext']);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
        $tokens = new MediaToken(self::SECRET);
        $target = new SocialPostTarget(Platform::INSTAGRAM);
        (new \ReflectionProperty(SocialPostTarget::class, 'id'))->setValue($target, 7);
        $target->rendered(new Rendering('/var/storage/social/3/instagram.mp4', '/var/storage/social/3/instagram.jpg', 30.0, 1080, 1920, 1000));

        $urls = new MediaUrls($generator, $tokens);
        $film = $urls->for($target);
        $cover = $urls->for($target, true);

        self::assertSame('https://site.example/social/media/7/'.$tokens->sign(7, '/var/storage/social/3/instagram.mp4').'.mp4', $film);
        self::assertSame('https://site.example/social/media/7/'.$tokens->sign(7, '/var/storage/social/3/instagram.jpg').'.jpg', $cover);
        self::assertSame(['social_media', UrlGeneratorInterface::ABSOLUTE_URL], [$generator->calls[0][0], $generator->calls[0][2]]);
        // What the controller checks: the token in the address names the file.
        $token = $generator->calls[0][1]['token'];
        self::assertTrue($tokens->verify(7, (string) $target->getRenderedPath(), $token));
        self::assertFalse($tokens->verify(7, (string) $target->getCoverPath(), $token));
        self::assertSame($film, ($urls->callable())($target, false));
    }
}
