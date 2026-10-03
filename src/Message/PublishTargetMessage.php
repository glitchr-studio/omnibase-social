<?php

namespace Base\Social\Message;

/** A rendered post to hand to one network. */
final class PublishTargetMessage
{
    public function __construct(public readonly int $targetId)
    {
    }
}
