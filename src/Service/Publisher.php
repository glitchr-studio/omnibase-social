<?php

namespace Base\Social\Service;

use Base\Social\Entity\SocialPost;
use Base\Social\Entity\SocialPostTarget;
use Base\Social\Enum\PostState;
use Base\Social\Enum\TargetState;
use Base\Social\Exception\RenderingException;
use Base\Social\Message\CheckTargetMessage;
use Base\Social\Message\PublishTargetMessage;
use Base\Social\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnipost\Exception\OmnipostException;
use Omnipost\Exception\UnauthorizedException;
use Omnipost\Model\Media;
use Omnipost\Model\MediaKind;
use Omnipost\Model\Post;
use Omnipost\Model\PostKind;
use Omnipost\Model\Publication;
use Omnipost\Model\PublicationState;
use Omnipost\Model\Violation;
use Omnipost\Platform;
use Omnipost\PublisherInterface;
use Omnipost\Validator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The pipeline, the handlers only call it: a post rendered for each of its
 * networks, each rendering checked against what the network takes, handed
 * over, then its status read again until the network says it is online -
 * or why it is not.
 */
class Publisher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Renderer $renderer,
        private readonly TemplateRepository $templates,
        private readonly Accounts $accounts,
        private readonly MediaUrls $urls,
        private readonly Validator $validator,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger = new NullLogger(),
        #[Autowire('%social.check.delay%')] private readonly int $checkDelay = 30,
        #[Autowire('%social.check.attempts%')] private readonly int $checkAttempts = 20,
    ) {
    }

    // ── Rendering ─────────────────────────────────────────────────────

    /**
     * Each network's film made with its template (the target's, else the
     * post's, else the network's, else the site's). A rendering that fails
     * fails its network alone. $publish: the rendered ones then handed over -
     * at the post's hour when it has one to come.
     */
    public function render(SocialPost $post, bool $publish = false): void
    {
        $post->setState(PostState::RENDERING);
        $this->entityManager->flush();

        foreach ($post->getEnabledTargets() as $target) {
            if (TargetState::PUBLISHED === $target->getState()) {
                continue;
            }
            try {
                $platform = Platform::tryFrom((string) $target->getPlatform());
                $target->rendered($this->renderer->render($post, $this->templates->resolve($target), $platform));
            } catch (RenderingException $e) {
                $this->logger->error('social: rendering for {platform} failed: {error}', ['platform' => $target->getPlatform(), 'error' => $e->getMessage()]);
                $target->fail($e->getMessage());
            }
            $this->entityManager->flush();
        }

        $post->refreshState();
        $this->entityManager->flush();

        if ($publish) {
            $this->dispatchPublish($post);
        }
    }

    /** The rendered networks handed over (Messenger), at the post's hour when it is to come. */
    public function dispatchPublish(SocialPost $post): int
    {
        $delay = $post->getScheduledAt() ? max(0, $post->getScheduledAt()->getTimestamp() - time()) : 0;
        $count = 0;
        foreach ($post->getEnabledTargets() as $target) {
            if (TargetState::RENDERED !== $target->getState()) {
                continue;
            }
            $this->bus->dispatch(new PublishTargetMessage((int) $target->getId()), $delay > 0 ? [new DelayStamp($delay * 1000)] : []);
            ++$count;
        }

        return $count;
    }

    // ── Checking ──────────────────────────────────────────────────────

    /**
     * The post as $target's network will receive it - its own rendering,
     * caption, title and hashtags - with the public addresses when the
     * rendering exists, the local paths otherwise.
     */
    public function postFor(SocialPostTarget $target, bool $publicUrls = true): Post
    {
        $post = $target->getPost() ?? throw new \LogicException('A target without its post.');
        $platform = Platform::from((string) $target->getPlatform());
        $urls = $publicUrls && $target->getId() && $target->getRenderedPath() ? $this->urls->callable() : null;

        return $post->toPost($urls)->for($platform);
    }

    /**
     * What the network would refuse, before anything is sent: the post
     * against its capabilities (Omnipost\Validator), and against the
     * provider's own rules when it has some (a reel is 9:16 on Instagram).
     * A target not rendered yet is checked as it will be: the source's
     * length, the template's frame.
     *
     * @return list<Violation>
     */
    public function validate(SocialPostTarget $target): array
    {
        try {
            $provider = $this->accounts->provider((string) $target->getPlatform());
        } catch (OmnipostException $e) {
            return [new Violation('provider', $e->getMessage())];
        }
        if (!$provider instanceof PublisherInterface) {
            return [new Violation('provider', \sprintf('"%s" does not publish.', $target->getPlatform()))];
        }

        $post = $target->getRenderedPath() ? $this->postFor($target, false) : $this->projection($target);
        $violations = $this->validator->validate($post, $provider->capabilities());
        if (method_exists($provider, 'validate')) {
            $violations = array_merge($violations, (array) $provider->validate($post));
        }

        // The same complaint, once.
        $unique = [];
        foreach ($violations as $violation) {
            $unique[(string) $violation] = $violation;
        }

        return array_values($unique);
    }

    // ── Publishing ────────────────────────────────────────────────────

    /**
     * $target handed to its network. Refused by the checks: FAILED with the
     * reasons, nothing sent. Taken: the network's id kept, SENT (or
     * PROCESSING, or at once PUBLISHED), and its status to be read again in
     * check.delay seconds.
     */
    public function publish(SocialPostTarget $target): void
    {
        if (!$target->isEnabled() || $target->getState()->isInFlight() || TargetState::PUBLISHED === $target->getState()) {
            return;
        }
        if (!$target->hasRendering()) {
            $this->settle($target->fail('Not rendered: render the post first.'));

            return;
        }

        $violations = $this->validate($target);
        if ($violations) {
            $this->settle($target->fail(implode("\n", array_map('strval', $violations))));

            return;
        }

        try {
            /** @var PublisherInterface $provider */
            $provider = $this->accounts->provider((string) $target->getPlatform());
            $target->resetAttempts();
            $this->apply($target, $provider->publish($this->postFor($target)));
        } catch (OmnipostException $e) {
            $this->settle($target->fail(self::explain($e)));

            return;
        }

        $this->settle($target);
        if ($target->getState()->isInFlight()) {
            $this->bus->dispatch(new CheckTargetMessage((int) $target->getId()), [new DelayStamp($this->checkDelay * 1000)]);
        }
    }

    /**
     * The publication's status read again. Still processing: read again
     * later, up to check.attempts times - then given up as FAILED.
     *
     * @return bool whether it is to be read again
     */
    public function check(SocialPostTarget $target): bool
    {
        if (!$target->getState()->isInFlight() || null === $target->getPublicationId()) {
            return false;
        }

        $attempt = $target->attempt();
        try {
            /** @var PublisherInterface $provider */
            $provider = $this->accounts->provider((string) $target->getPlatform());
            $this->apply($target, $provider->status((string) $target->getPublicationId()));
        } catch (UnauthorizedException $e) {
            $target->fail(self::explain($e));
        } catch (OmnipostException $e) {
            // A network that does not answer once is asked again.
            $this->logger->warning('social: status of {platform} unread: {error}', ['platform' => $target->getPlatform(), 'error' => $e->getMessage()]);
        }

        if ($target->getState()->isInFlight() && $attempt >= $this->checkAttempts) {
            $target->fail(\sprintf('Still processing on %s after %d looks: see the account itself.', $target->getPlatformLabel(), $attempt));
        }
        $this->settle($target);

        $again = $target->getState()->isInFlight();
        if ($again) {
            $this->bus->dispatch(new CheckTargetMessage((int) $target->getId()), [new DelayStamp($this->checkDelay * 1000)]);
        }

        return $again;
    }

    /** A failed network sent again: rendered again first when its film is gone. */
    public function retry(SocialPostTarget $target): void
    {
        $target->resetAttempts();
        if (!$target->hasRendering()) {
            $post = $target->getPost();
            try {
                $target->rendered($this->renderer->render($post, $this->templates->resolve($target), Platform::tryFrom((string) $target->getPlatform())));
            } catch (RenderingException $e) {
                $this->settle($target->fail($e->getMessage()));

                return;
            }
        } else {
            $target->setState(TargetState::RENDERED);
        }
        $this->settle($target);
        $this->bus->dispatch(new PublishTargetMessage((int) $target->getId()));
    }

    private function apply(SocialPostTarget $target, Publication $publication): void
    {
        $target->setPublicationId($publication->id ?: $target->getPublicationId());
        match ($publication->state) {
            PublicationState::PUBLISHED => $target
                ->setPermalink($publication->permalink)
                ->setPublishedAt($publication->publishedAt ? \DateTime::createFromImmutable($publication->publishedAt) : new \DateTime())
                ->setState(TargetState::PUBLISHED),
            PublicationState::FAILED => $target->fail($publication->error ?? 'Refused by the network.'),
            PublicationState::PROCESSING => $target->setState(TargetState::PROCESSING),
            PublicationState::PENDING => $target->setState(TargetState::SENT),
        };
    }

    private function settle(SocialPostTarget $target): void
    {
        $target->getPost()?->refreshState();
        $this->entityManager->flush();
    }

    /**
     * The post as it will be once rendered, to check it before: the
     * template's frame (1080 x 1920) or the source's, the source's length.
     */
    private function projection(SocialPostTarget $target): Post
    {
        $post = $target->getPost();
        $platform = Platform::from((string) $target->getPlatform());
        $kind = $post->getPostKind();
        $image = PostKind::IMAGE === $kind;
        $source = $image ? $post->getImageFile()?->getPathname() : $post->getVideoFile()?->getPathname();

        $probe = ['duration' => null, 'width' => null, 'height' => null, 'size' => null, 'mime' => null];
        if ($source) {
            try {
                $probe = $this->renderer->probe($source);
            } catch (RenderingException) {
                // No ffprobe here: what can be checked without the measures is.
            }
        }
        if (!$image && null !== $this->templates->resolve($target)) {
            [$probe['width'], $probe['height'], $probe['size']] = [Renderer::WIDTH, Renderer::HEIGHT, null];
            $probe['mime'] = 'video/mp4';
        }

        $media = $source ? [new Media($image ? MediaKind::IMAGE : MediaKind::VIDEO, 'file://'.$source, $source, $probe['mime'] ?: null, $probe['width'] ?: null, $probe['height'] ?: null, $image ? null : ($probe['duration'] ?: null), $probe['size'] ?: null)] : [];
        $canonical = $post->toPost();

        return (new Post($kind, $media, $canonical->caption, $canonical->title, $canonical->tags, null, null, $canonical->variants, [], $canonical->link))
            ->for($platform);
    }

    /** What the back office shows: a dead token says to connect the account again. */
    private static function explain(OmnipostException $e): string
    {
        return $e instanceof UnauthorizedException
            ? $e->getMessage().' — connect the account again (Social › Accounts).'
            : $e->getMessage();
    }
}
