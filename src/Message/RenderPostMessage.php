<?php

namespace Base\Social\Message;

/** A post to render for each of its networks - and, when $publish says so, to send once rendered. */
final class RenderPostMessage
{
    public function __construct(
        public readonly int $postId,
        public readonly bool $publish = false,
    ) {
    }
}
