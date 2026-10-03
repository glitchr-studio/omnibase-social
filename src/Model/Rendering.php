<?php

namespace Base\Social\Model;

/** What the renderer made: the film, its cover, and what ffprobe measured. */
final class Rendering
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $coverPath,
        /** seconds */
        public readonly float $duration,
        public readonly int $width,
        public readonly int $height,
        /** bytes */
        public readonly int $size,
    ) {
    }
}
