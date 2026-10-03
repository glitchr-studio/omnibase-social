<?php

namespace Base\Social\Enum;

/** Where the logo sits on the reel. A string enum, so the column reads plainly. */
enum LogoPosition: string
{
    case TOP_LEFT = 'top_left';
    case TOP_RIGHT = 'top_right';
    case BOTTOM_LEFT = 'bottom_left';
    case BOTTOM_RIGHT = 'bottom_right';
    case CENTER = 'center';

    /**
     * The overlay's x:y for ffmpeg (W, H: the frame; w, h: the logo), $margin
     * pixels from the edges.
     */
    public function overlay(int $margin): string
    {
        return match ($this) {
            self::TOP_LEFT => "$margin:$margin",
            self::TOP_RIGHT => "W-w-$margin:$margin",
            self::BOTTOM_LEFT => "$margin:H-h-$margin",
            self::BOTTOM_RIGHT => "W-w-$margin:H-h-$margin",
            self::CENTER => '(W-w)/2:(H-h)/2',
        };
    }
}
