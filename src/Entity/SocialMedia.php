<?php

namespace Base\Social\Entity;

use Base\Database\Attribute\Uploader;
use Base\Social\Repository\SocialMediaRepository;
use Doctrine\ORM\Mapping as ORM;
use Omnipost\Model\PostKind;
use Omnipost\Platform;
use Symfony\Component\HttpFoundation\File\File;

/**
 * One of the account's own posts, as the network listed it at the last
 * sync: what the wall of the site shows. The thumbnail is a local copy -
 * the networks' own addresses expire within days -, the film stays where
 * it is (videoUrl, played muted on hover while it still answers).
 */
#[ORM\Entity(repositoryClass: SocialMediaRepository::class)]
#[ORM\Table(name: 'social_media')]
#[ORM\UniqueConstraint(name: 'social_media_remote_unique', columns: ['provider', 'remote_id'])]
#[ORM\Index(columns: ['provider', 'publishedAt'], name: 'social_media_published_idx')]
class SocialMedia
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-table-cells'];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /** The omnipost provider it was read through: "instagram", "youtube"... */
    #[ORM\Column(length: 32)]
    protected ?string $provider = null;

    #[ORM\Column(name: 'remote_id', length: 191)]
    protected ?string $remoteId = null;

    /** An Omnipost\Model\PostKind value. */
    #[ORM\Column(length: 16)]
    protected string $kind = PostKind::IMAGE->value;

    #[ORM\Column(length: 1024)]
    protected ?string $permalink = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $caption = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $publishedAt = null;

    /**
     * The local copy. fetch: an address put here is downloaded into the
     * storage when the row is saved (Service\FeedSync hands the file itself,
     * so a picture that does not answer costs the row nothing).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '8MB', mime_types: ['image/*'], fetch: true)]
    protected $thumbnail = null;

    /** The network's address of the picture (or of the film's still): it may expire. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $mediaUrl = null;

    /** The network's address of the film: it may expire. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $videoUrl = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $likes = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $comments = null;

    /** Kept off the wall. */
    #[ORM\Column(type: 'boolean')]
    protected bool $hidden = false;

    #[ORM\Column(type: 'datetime')]
    protected ?\DateTimeInterface $fetchedAt = null;

    public function __construct(?string $provider = null, ?string $remoteId = null)
    {
        $this->provider = $provider;
        $this->remoteId = $remoteId;
        $this->fetchedAt = new \DateTime();
    }

    public function __toString(): string
    {
        return mb_strimwidth((string) ($this->caption ?: $this->permalink), 0, 60, '…');
    }

    public function getId(): ?int { return $this->id; }

    public function getProvider(): ?string { return $this->provider; }
    public function getProviderLabel(): string { return Platform::tryFrom((string) $this->provider)?->label() ?? (string) $this->provider; }
    public function getRemoteId(): ?string { return $this->remoteId; }

    public function getKind(): string { return $this->kind; }
    public function setKind(PostKind|string $kind): self { $this->kind = $kind instanceof PostKind ? $kind->value : $kind; return $this; }
    public function isVideo(): bool { return \in_array($this->kind, [PostKind::REEL->value, PostKind::VIDEO->value], true); }

    public function getPermalink(): ?string { return $this->permalink; }
    public function setPermalink(?string $permalink): self { $this->permalink = $permalink; return $this; }

    public function getCaption(): ?string { return $this->caption; }
    public function setCaption(?string $caption): self { $this->caption = $caption; return $this; }

    public function getPublishedAt(): ?\DateTimeInterface { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeInterface $at): self { $this->publishedAt = $at; return $this; }

    public function getThumbnail(): ?string { return Uploader::getPublic($this, 'thumbnail'); }
    public function getThumbnailFile(): ?File { return Uploader::get($this, 'thumbnail'); }
    public function setThumbnail($thumbnail): self { $this->thumbnail = $thumbnail; return $this; }
    public function hasThumbnail(): bool { return null !== $this->thumbnail; }

    public function getMediaUrl(): ?string { return $this->mediaUrl; }
    public function setMediaUrl(?string $url): self { $this->mediaUrl = $url; return $this; }

    public function getVideoUrl(): ?string { return $this->videoUrl; }
    public function setVideoUrl(?string $url): self { $this->videoUrl = $url; return $this; }

    public function getLikes(): ?int { return $this->likes; }
    public function setLikes(?int $likes): self { $this->likes = $likes; return $this; }

    public function getComments(): ?int { return $this->comments; }
    public function setComments(?int $comments): self { $this->comments = $comments; return $this; }

    public function isHidden(): bool { return $this->hidden; }
    public function getHidden(): bool { return $this->hidden; }
    public function setHidden(bool $hidden): self { $this->hidden = $hidden; return $this; }

    public function getFetchedAt(): ?\DateTimeInterface { return $this->fetchedAt; }
    public function touch(): self { $this->fetchedAt = new \DateTime(); return $this; }
}
