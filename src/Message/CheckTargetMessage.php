<?php

namespace Base\Social\Message;

/** A publication the network is still processing: its status to read again. */
final class CheckTargetMessage
{
    public function __construct(public readonly int $targetId)
    {
    }
}
