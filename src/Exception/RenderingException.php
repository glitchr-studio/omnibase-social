<?php

namespace Base\Social\Exception;

/** ffmpeg is missing, or refused the film: the message says which. */
final class RenderingException extends \RuntimeException
{
    public static function missing(string $binary): self
    {
        return new self(\sprintf('"%s" was not found: install ffmpeg in the image that renders (apt-get install -y ffmpeg fonts-dejavu-core) or set social.ffmpeg.binary / social.ffmpeg.ffprobe to its path.', $binary));
    }

    public static function failed(string $what, string $output): self
    {
        // ffmpeg's last lines say what went wrong; the banner above them does not.
        $lines = array_slice(array_filter(array_map('trim', explode("\n", $output))), -6);

        return new self(\sprintf('%s failed: %s', $what, implode(' | ', $lines) ?: 'no output'));
    }
}
