<?php

namespace Base\Social\Tests\Entity;

use Base\Social\Entity\SocialPost;
use Base\Social\Entity\SocialPostTarget;
use Base\Social\Enum\PostState;
use Base\Social\Enum\TargetState;
use Base\Social\Model\Rendering;
use Omnipost\Model\MediaKind;
use Omnipost\Model\PostKind;
use Omnipost\Platform;
use PHPUnit\Framework\TestCase;

final class SocialPostTest extends TestCase
{
    public function testToPostBuildsTheCanonicalPostAndAVariantPerNetwork(): void
    {
        $post = (new SocialPost())
            ->setCaption('Bach, the Chaconne, at the Salle Cortot.')
            ->setTitle('Chaconne')
            ->setTags(['#violin', 'bach', ' ', 'bach'])
            ->setLink('https://brieucvourch.example/concerts');
        $instagram = self::target($post, Platform::INSTAGRAM, 1, '/s/1/instagram.mp4', '/s/1/instagram.jpg');
        $instagram->setOptions(['share_to_feed' => true, 'privacy' => null]);
        $youtube = self::target($post, Platform::YOUTUBE, 2, '/s/1/youtube.mp4', null)
            ->setCaption('The Chaconne in full on the site.')
            ->setTitle('Bach - Chaconne (excerpt) #Shorts')
            ->setTags(['shorts', 'violin']);
        self::target($post, Platform::TIKTOK, 3, '/s/1/tiktok.mp4', null)->setEnabled(false);

        $urls = static fn (SocialPostTarget $target, bool $cover): string => 'https://site.example/social/media/'.$target->getId().($cover ? '.jpg' : '.mp4');
        $canonical = $post->toPost($urls);

        self::assertSame(PostKind::REEL, $canonical->kind);
        self::assertSame('Bach, the Chaconne, at the Salle Cortot.', $canonical->caption);
        self::assertSame('Chaconne', $canonical->title);
        self::assertSame(['violin', 'bach'], $canonical->tags);
        self::assertSame('https://brieucvourch.example/concerts', $canonical->link);
        self::assertNull($canonical->scheduledAt);
        // The disabled network is not there.
        self::assertSame(['instagram', 'youtube'], array_keys($canonical->variants));
        // The canonical media: the first network's rendering.
        self::assertSame('https://site.example/social/media/1.mp4', $canonical->media[0]->url);
        self::assertSame('https://site.example/social/media/1.jpg', $canonical->coverUrl);

        $media = $canonical->variant(Platform::INSTAGRAM)->media[0];
        self::assertSame(MediaKind::VIDEO, $media->kind);
        self::assertSame('/s/1/instagram.mp4', $media->path);
        self::assertSame('video/mp4', $media->mime);
        self::assertSame('9:16', $media->aspectRatio());
        self::assertSame(30.5, $media->duration);
        self::assertSame(12_000_000, $media->size);
        self::assertSame(['share_to_feed' => true], $canonical->variant(Platform::INSTAGRAM)->options);
        // No override: null, the post's stands.
        self::assertNull($canonical->variant(Platform::INSTAGRAM)->caption);
    }

    public function testForAppliesTheTargetsCaptionTitleTagsAndRendering(): void
    {
        $post = (new SocialPost())->setCaption('The post\'s caption')->setTitle('The title')->setTags(['harp']);
        self::target($post, Platform::INSTAGRAM, 1, '/s/2/instagram.mp4', null);
        self::target($post, Platform::YOUTUBE, 2, '/s/2/youtube.mp4', '/s/2/youtube.jpg')
            ->setCaption('  YouTube\'s own caption ')
            ->setTitle('Debussy - Danses #Shorts')
            ->setTags(['#shorts', 'harp', 'debussy']);

        $instagram = $post->toPost()->for(Platform::INSTAGRAM);
        self::assertSame('The post\'s caption', $instagram->caption);
        self::assertSame('The title', $instagram->title);
        self::assertSame(['harp'], $instagram->tags);
        self::assertSame('/s/2/instagram.mp4', $instagram->media[0]->path);
        self::assertSame("The post's caption\n\n#harp", $instagram->text());

        $youtube = $post->toPost()->for(Platform::YOUTUBE);
        self::assertSame('YouTube\'s own caption', $youtube->caption);
        self::assertSame('Debussy - Danses #Shorts', $youtube->title);
        self::assertSame(['shorts', 'harp', 'debussy'], $youtube->tags);
        self::assertSame('/s/2/youtube.mp4', $youtube->media[0]->path);
        // Without public addresses, the local files stand in.
        self::assertSame('file:///s/2/youtube.jpg', $youtube->coverUrl);
        self::assertSame([], $youtube->variants);
    }

    public function testAnEmptyOverrideKeepsThePostsAndAPictureIsAnImage(): void
    {
        $post = (new SocialPost())->setKind(PostKind::IMAGE)->setCaption('Rehearsal');
        $target = self::target($post, Platform::INSTAGRAM, 1, '/s/3/photo.jpg', null)->setCaption('   ')->setTags([]);

        self::assertNull($target->getCaption());
        self::assertNull($target->getTags());
        $instagram = $post->toPost()->for(Platform::INSTAGRAM);
        self::assertSame(PostKind::IMAGE, $instagram->kind);
        self::assertSame(MediaKind::IMAGE, $instagram->media[0]->kind);
        self::assertSame('image/jpeg', $instagram->media[0]->mime);
        self::assertSame('Rehearsal', $instagram->caption);
    }

    public function testTheStateFollowsTheNetworks(): void
    {
        $post = new SocialPost();
        $a = self::target($post, Platform::INSTAGRAM, 1, '/a.mp4', null);
        $b = self::target($post, Platform::YOUTUBE, 2, '/b.mp4', null);
        self::assertSame(PostState::READY, $post->refreshState()->getState());

        $a->setState(TargetState::PROCESSING);
        self::assertSame(PostState::PUBLISHING, $post->refreshState()->getState());

        $a->setState(TargetState::PUBLISHED);
        $b->fail('Refused.');
        self::assertSame(PostState::FAILED, $post->refreshState()->getState());

        $b->setState(TargetState::PUBLISHED);
        self::assertSame(PostState::DONE, $post->refreshState()->getState());
    }

    public function testTheFilesAddressClosesADayAfterTheEnd(): void
    {
        $target = new SocialPostTarget(Platform::INSTAGRAM);
        self::assertTrue($target->isMediaOpen());
        $target->setState(TargetState::PUBLISHED);
        self::assertTrue($target->isMediaOpen(86400));
        self::assertFalse($target->isMediaOpen(86400, new \DateTime('+2 days')));
    }

    private static function target(SocialPost $post, Platform $platform, int $id, string $path, ?string $cover): SocialPostTarget
    {
        $target = new SocialPostTarget($platform);
        (new \ReflectionProperty(SocialPostTarget::class, 'id'))->setValue($target, $id);
        $target->rendered(new Rendering($path, $cover, 30.5, 1080, 1920, 12_000_000));
        $post->addTarget($target);

        return $target;
    }
}
