<?php

namespace Base\Social\Entity;

use Base\Social\Enum\TargetState;
use Base\Social\Model\Rendering;
use Base\Social\Repository\SocialPostTargetRepository;
use Doctrine\ORM\Mapping as ORM;
use Omnipost\Model\Media;
use Omnipost\Model\MediaKind;
use Omnipost\Model\PostKind;
use Omnipost\Platform;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A post on one network: whether it goes there, what it says there when it
 * is not what the post says (a shorter caption, a title, other hashtags, a
 * template of its own), the network's extras (share_to_feed, privacy), then
 * what the pipeline wrote - the rendered file and its measures, the
 * network's id, the permalink, the error.
 */
#[ORM\Entity(repositoryClass: SocialPostTargetRepository::class)]
#[ORM\Table(name: 'social_post_target')]
#[ORM\UniqueConstraint(name: 'social_post_target_unique', columns: ['post_id', 'platform'])]
class SocialPostTarget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: SocialPost::class, inversedBy: 'targets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?SocialPost $post = null;

    /** An Omnipost\Platform value - and the name of the provider that serves it. */
    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    protected ?string $platform = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $enabled = true;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $caption = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    protected ?string $title = null;

    /** Null: the post's hashtags. */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $tags = null;

    #[ORM\ManyToOne(targetEntity: Template::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Template $template = null;

    /** The network's extras: share_to_feed (Instagram), privacy (YouTube)... */
    #[ORM\Column(type: 'json')]
    protected array $options = [];

    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $renderedPath = null;

    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $coverPath = null;

    /** seconds */
    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $duration = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $width = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $height = null;

    /** bytes */
    #[ORM\Column(type: 'bigint', nullable: true)]
    protected $size = null;

    /** The network's id for it: what its status is read by. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $publicationId = null;

    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $permalink = null;

    #[ORM\Column(type: 'string', length: 16, enumType: TargetState::class)]
    protected TargetState $state = TargetState::PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $error = null;

    /** Looks at the network's status so far. */
    #[ORM\Column(type: 'smallint')]
    protected int $attempts = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $publishedAt = null;

    /** When the state last moved: the public address of the file closes a while after the end. */
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $updatedAt = null;

    public function __construct(Platform|string|null $platform = null)
    {
        $this->setPlatform($platform);
    }

    public function __toString(): string
    {
        return $this->getPlatformLabel();
    }

    public function getId(): ?int { return $this->id; }

    public function getPost(): ?SocialPost { return $this->post; }
    public function setPost(?SocialPost $post): self { $this->post = $post; return $this; }

    public function getPlatform(): ?string { return $this->platform; }
    public function setPlatform(Platform|string|null $platform): self
    {
        $this->platform = $platform instanceof Platform ? $platform->value : ($platform ?: null);

        return $this;
    }

    public function getPlatformLabel(): string
    {
        return Platform::tryFrom((string) $this->platform)?->label() ?? (string) $this->platform;
    }

    public function isEnabled(): bool { return $this->enabled; }
    public function getEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }

    public function getCaption(): ?string { return $this->caption; }
    public function setCaption(?string $caption): self { $this->caption = null !== $caption && '' !== trim($caption) ? trim($caption) : null; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = null !== $title && '' !== trim($title) ? trim($title) : null; return $this; }

    /** @return list<string>|null */
    public function getTags(): ?array { return $this->tags; }
    public function setTags(?array $tags): self { $this->tags = SocialPost::cleanTags($tags ?? []) ?: null; return $this; }

    public function getTemplate(): ?Template { return $this->template; }
    public function setTemplate(?Template $template): self { $this->template = $template; return $this; }

    public function getOptions(): array { return $this->options; }
    public function setOptions(?array $options): self { $this->options = array_filter($options ?? [], static fn ($value) => null !== $value && '' !== $value); return $this; }

    public function getRenderedPath(): ?string { return $this->renderedPath; }
    public function getCoverPath(): ?string { return $this->coverPath; }
    public function getDuration(): ?float { return $this->duration; }
    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function getSize(): ?int { return null !== $this->size ? (int) $this->size : null; }

    /** What the renderer made, written down; the target is RENDERED. */
    public function rendered(Rendering $rendering): self
    {
        $this->renderedPath = $rendering->path;
        $this->coverPath = $rendering->coverPath;
        $this->duration = $rendering->duration ?: null;
        $this->width = $rendering->width ?: null;
        $this->height = $rendering->height ?: null;
        $this->size = $rendering->size;
        $this->publicationId = null;
        $this->permalink = null;
        $this->publishedAt = null;
        $this->error = null;
        $this->attempts = 0;

        return $this->setState(TargetState::RENDERED);
    }

    public function hasRendering(): bool
    {
        return null !== $this->renderedPath && is_file($this->renderedPath);
    }

    public function getPublicationId(): ?string { return $this->publicationId; }
    public function setPublicationId(?string $id): self { $this->publicationId = $id; return $this; }

    public function getPermalink(): ?string { return $this->permalink; }
    public function setPermalink(?string $permalink): self { $this->permalink = $permalink; return $this; }

    public function getState(): TargetState { return $this->state; }
    public function setState(TargetState $state): self
    {
        $this->state = $state;
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public function getError(): ?string { return $this->error; }

    public function fail(string $error): self
    {
        $this->error = $error;

        return $this->setState(TargetState::FAILED);
    }

    public function getAttempts(): int { return $this->attempts; }
    public function attempt(): int { return ++$this->attempts; }
    public function resetAttempts(): self { $this->attempts = 0; $this->error = null; return $this; }

    public function getPublishedAt(): ?\DateTimeInterface { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeInterface $at): self { $this->publishedAt = $at; return $this; }

    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }

    /**
     * Whether the public address of its file answers: while the publication
     * is on its way, and $ttl seconds after it ended - a network may come
     * back for the file a little later, a stranger should not find it for long.
     */
    public function isMediaOpen(int $ttl = 86400, ?\DateTimeInterface $now = null): bool
    {
        if (!$this->state->isFinal()) {
            return true;
        }
        $since = $this->updatedAt ?? $this->publishedAt;

        return null !== $since && ($now ?? new \DateTime())->getTimestamp() - $since->getTimestamp() <= $ttl;
    }

    /**
     * The rendered file as Omnipost wants it: its public address from $urls
     * (`fn (SocialPostTarget $target, bool $cover): string`), the local path
     * for the providers that upload and for the Validator to measure.
     */
    public function toMedia(PostKind $kind, ?callable $urls = null): ?Media
    {
        if (null === $this->renderedPath) {
            return null;
        }
        $url = $urls ? $urls($this, false) : 'file://'.$this->renderedPath;
        $extension = strtolower(pathinfo($this->renderedPath, \PATHINFO_EXTENSION));
        if (PostKind::IMAGE === $kind || \in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $mime = match ($extension) { 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', default => 'image/jpeg' };

            return new Media(MediaKind::IMAGE, $url, $this->renderedPath, $mime, $this->width, $this->height, null, $this->getSize());
        }
        $mime = match ($extension) { 'mov' => 'video/quicktime', 'webm' => 'video/webm', default => 'video/mp4' };
        $cover = $this->coverPath ? ($urls ? $urls($this, true) : 'file://'.$this->coverPath) : null;

        return new Media(MediaKind::VIDEO, $url, $this->renderedPath, $mime, $this->width, $this->height, $this->duration, $this->getSize(), $cover);
    }
}
