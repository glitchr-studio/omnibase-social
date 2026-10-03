<?php

namespace Base\Social\Enum;

/**
 * Where a post is on one network: waiting, rendered, handed over, being
 * processed there, online, or refused.
 */
enum TargetState: string
{
    case PENDING = 'pending';
    case RENDERED = 'rendered';
    case SENT = 'sent';
    case PROCESSING = 'processing';
    case PUBLISHED = 'published';
    case FAILED = 'failed';

    public function isFinal(): bool
    {
        return self::PUBLISHED === $this || self::FAILED === $this;
    }

    /** Handed to the network and not settled yet: its status is still to be read. */
    public function isInFlight(): bool
    {
        return self::SENT === $this || self::PROCESSING === $this;
    }
}
