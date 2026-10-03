<?php

namespace Base\Social\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The signature of a rendered file's public address: an HMAC of the
 * target's id and the file's path with the kernel's secret. Nobody guesses
 * it, it names one file, and a file rendered again gets another.
 */
final class MediaToken
{
    public const LENGTH = 32;

    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
    }

    public function sign(int $targetId, string $path): string
    {
        return substr(hash_hmac('sha256', $targetId."\n".$path, $this->secret), 0, self::LENGTH);
    }

    public function verify(int $targetId, string $path, string $token): bool
    {
        return self::LENGTH === \strlen($token) && hash_equals($this->sign($targetId, $path), $token);
    }
}
