<?php

namespace Base\Social\Service;

use Base\Social\Entity\SocialPost;
use Base\Social\Entity\Template;
use Base\Social\Enum\Fit;
use Base\Social\Enum\LogoPosition;
use Base\Social\Exception\RenderingException;
use Base\Social\Model\Rendering;
use Omnipost\Platform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The reel made from the source film and the site's template, with ffmpeg:
 * the source brought to 1080 x 1920 (cropped, or whole on the background),
 * the logo laid in its corner, the band drawn across the lower third with
 * its text, the film faded in and out, the intro before and the outro
 * after, the sound kept. One H.264/AAC file per network, as they all take
 * it, and a still for its cover.
 *
 * command() only assembles ffmpeg's arguments - no file is touched -, so
 * the filter graph is read and tested as it is.
 */
class Renderer
{
    public const WIDTH = 1080;
    public const HEIGHT = 1920;

    /** Pixels between the logo and the frame's edges. */
    public const MARGIN = 64;

    /** The band: across the lower third, above what the networks lay over the bottom of a reel. */
    public const BAND_Y = 1380;
    public const BAND_HEIGHT = 140;
    public const FONT_SIZE = 56;
    public const MIN_FONT_SIZE = 28;

    public function __construct(
        #[Autowire('%social.ffmpeg.binary%')] private readonly string $ffmpeg = 'ffmpeg',
        #[Autowire('%social.ffmpeg.ffprobe%')] private readonly string $ffprobe = 'ffprobe',
        #[Autowire('%social.ffmpeg.crf%')] private readonly int $crf = 20,
        #[Autowire('%social.ffmpeg.fps%')] private readonly int $fps = 30,
        #[Autowire('%social.ffmpeg.timeout%')] private readonly int $timeout = 900,
        #[Autowire('%social.ffmpeg.font%')] private readonly string $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        #[Autowire('%social.storage%')] private readonly string $storage = '/tmp/social',
    ) {
    }

    /**
     * The post rendered for $platform with $template, under
     * <storage>/<post id>/<platform>.mp4, its cover beside it. Without a
     * template - the post says "as it is", or the site has none - and for a
     * picture, the source itself is measured and given back.
     */
    public function render(SocialPost $post, ?Template $template = null, ?Platform $platform = null): Rendering
    {
        if ($post->isImage()) {
            $image = $post->getImageFile()?->getPathname() ?? throw new RenderingException('The post has no picture.');
            $probe = $this->probe($image);

            return new Rendering($image, null, 0.0, $probe['width'], $probe['height'], $probe['size']);
        }

        $source = $post->getVideoFile()?->getPathname() ?? throw new RenderingException('The post has no film.');
        $directory = rtrim($this->storage, '/').'/'.($post->getId() ?? 'draft');
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RenderingException(\sprintf('"%s" cannot be created.', $directory));
        }
        $name = $platform?->value ?? 'default';
        $cover = $directory.'/'.$name.'.jpg';

        if (null === $template) {
            // As it is: no second encoding, the networks get what was shot.
            $probe = $this->probe($source);
            $this->cover($source, min($post->getCoverSecond(), max(0.0, $probe['duration'] - 0.1)), $cover);

            return new Rendering($source, is_file($cover) ? $cover : null, $probe['duration'], $probe['width'], $probe['height'], $probe['size']);
        }

        $probe = $this->probe($source);
        $output = $directory.'/'.$name.'.mp4';
        $font = $template->getFontFile()?->getPathname() ?? $this->font;
        $this->run($this->command([
            'source' => $source,
            'output' => $output,
            'duration' => $probe['duration'],
            'audio' => $probe['audio'],
            'fit' => $template->getFit(),
            'background' => $template->getBackground(),
            'logo' => $template->getLogoFile()?->getPathname(),
            'logoPosition' => $template->getLogoPosition(),
            'logoScale' => $template->getLogoScale(),
            'band' => $template->hasBand(),
            'bandText' => $template->getBandText(),
            'bandColor' => $template->getBandColor(),
            'textColor' => $template->getTextColor(),
            'font' => is_file($font) ? $font : null,
            'fade' => $template->getFadeSeconds(),
            'intro' => $this->clip($template->getIntroFile()?->getPathname()),
            'outro' => $this->clip($template->getOutroFile()?->getPathname()),
        ]), 'The rendering');

        $made = $this->probe($output);
        $this->cover($output, min($post->getCoverSecond(), max(0.0, $made['duration'] - 0.1)), $cover);

        return new Rendering($output, is_file($cover) ? $cover : null, $made['duration'], $made['width'], $made['height'], $made['size']);
    }

    /**
     * ffmpeg's arguments for one rendering. $input:
     *
     *   source, output   paths (required)
     *   duration         the source's, in seconds: where the fade out starts, how long a silence lasts
     *   audio            whether the source has sound (true); without, a silence is laid
     *   fit              Fit::COVER (crop to 9:16) or Fit::CONTAIN (whole, on the background)
     *   background       "#rrggbb", behind a contained source
     *   logo             path of a picture, or null; logoPosition (LogoPosition), logoScale (part of the width)
     *   band             bool; bandText, bandColor, textColor, font (path of a TTF/OTF, or null)
     *   fade             seconds in and out, 0 for none
     *   intro, outro     ['path' => ..., 'duration' => ..., 'audio' => bool] or null
     *   fps, crf         over the configured ones
     *
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    public function command(array $input): array
    {
        $input += [
            'duration' => null, 'audio' => true, 'fit' => Fit::COVER, 'background' => '#000000',
            'logo' => null, 'logoPosition' => LogoPosition::TOP_RIGHT, 'logoScale' => 0.2,
            'band' => false, 'bandText' => null, 'bandColor' => '#000000', 'textColor' => '#ffffff', 'font' => null,
            'fade' => 0.0, 'intro' => null, 'outro' => null, 'fps' => $this->fps, 'crf' => $this->crf,
        ];
        $fit = $input['fit'] instanceof Fit ? $input['fit'] : Fit::from((string) $input['fit']);
        $position = $input['logoPosition'] instanceof LogoPosition ? $input['logoPosition'] : LogoPosition::from((string) $input['logoPosition']);
        $fps = (int) $input['fps'];
        $duration = null !== $input['duration'] ? (float) $input['duration'] : null;
        $fade = (float) $input['fade'];
        // A fade longer than half the film would eat it.
        if (null !== $duration && $fade > $duration / 2) {
            $fade = 0.0;
        }

        $arguments = [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error', '-i', (string) $input['source']];
        $next = 1;
        $graph = [];

        // The source, to the frame.
        $graph[] = '[0:v]'.self::frame($fit, (string) $input['background'], $fps).'[base]';
        $last = 'base';

        // The logo, in its corner.
        if ($input['logo']) {
            $arguments[] = '-i';
            $arguments[] = (string) $input['logo'];
            $width = (int) (round(self::WIDTH * max(0.1, min(0.5, (float) $input['logoScale'])) / 2) * 2);
            $graph[] = \sprintf('[%d:v]scale=%d:-1[logo]', $next++, $width);
            $graph[] = \sprintf('[%s][logo]overlay=%s[logod]', $last, $position->overlay(self::MARGIN));
            $last = 'logod';
        }

        // The band and its text, then the fades.
        $dress = [];
        if ($input['band']) {
            $dress[] = \sprintf('drawbox=x=0:y=%d:w=%d:h=%d:color=%s:t=fill', self::BAND_Y, self::WIDTH, self::BAND_HEIGHT, self::color((string) $input['bandColor']));
            if ('' !== trim((string) $input['bandText'])) {
                $dress[] = \sprintf(
                    'drawtext=%s:text=%s:expansion=none:fontcolor=%s:fontsize=%d:x=(w-text_w)/2:y=%d+(%d-text_h)/2',
                    $input['font'] ? 'fontfile='.self::escape((string) $input['font']) : 'font=Sans',
                    self::escape(trim((string) $input['bandText'])),
                    self::color((string) $input['textColor']),
                    self::fontSize(trim((string) $input['bandText'])),
                    self::BAND_Y,
                    self::BAND_HEIGHT,
                );
            }
        }
        $audio = ['aresample=48000', 'aformat=sample_fmts=fltp:channel_layouts=stereo'];
        if ($fade > 0) {
            $dress[] = \sprintf('fade=t=in:st=0:d=%s', self::number($fade));
            $audio[] = \sprintf('afade=t=in:st=0:d=%s', self::number($fade));
            if (null !== $duration) {
                $dress[] = \sprintf('fade=t=out:st=%s:d=%s', self::number($duration - $fade), self::number($fade));
                $audio[] = \sprintf('afade=t=out:st=%s:d=%s', self::number($duration - $fade), self::number($fade));
            }
        }
        $dress[] = 'format=yuv420p';
        $graph[] = \sprintf('[%s]%s[v]', $last, implode(',', $dress));
        $graph[] = $input['audio'] ? '[0:a]'.implode(',', $audio).'[a]' : self::silence($duration, 'a');

        // The intro before, the outro after: each brought to the same frame, rate and sound first.
        $segments = [];
        foreach (['intro' => 'i', 'outro' => 'o'] as $key => $suffix) {
            $clip = $input[$key];
            if (!$clip) {
                continue;
            }
            $clip = \is_array($clip) ? $clip + ['duration' => null, 'audio' => true] : ['path' => (string) $clip, 'duration' => null, 'audio' => true];
            $arguments[] = '-i';
            $arguments[] = (string) $clip['path'];
            $graph[] = \sprintf('[%d:v]%s,format=yuv420p[v%s]', $next, self::frame(Fit::COVER, (string) $input['background'], $fps), $suffix);
            $graph[] = $clip['audio']
                ? \sprintf('[%d:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[a%s]', $next, $suffix)
                : self::silence(null !== $clip['duration'] ? (float) $clip['duration'] : null, 'a'.$suffix);
            ++$next;
            $segments[$key] = "[v$suffix][a$suffix]";
        }

        $video = 'v';
        $sound = 'a';
        if ($segments) {
            $graph[] = \sprintf('%s[v][a]%sconcat=n=%d:v=1:a=1[vout][aout]', $segments['intro'] ?? '', $segments['outro'] ?? '', 1 + \count($segments));
            $video = 'vout';
            $sound = 'aout';
        }

        return array_merge($arguments, [
            '-filter_complex', implode(';', $graph),
            '-map', "[$video]", '-map', "[$sound]",
            '-c:v', 'libx264', '-profile:v', 'high', '-level', '4.1', '-pix_fmt', 'yuv420p',
            '-r', (string) $fps, '-crf', (string) (int) $input['crf'],
            '-c:a', 'aac', '-b:a', '160k', '-ar', '48000',
            '-movflags', '+faststart',
            (string) $input['output'],
        ]);
    }

    /**
     * ffmpeg's arguments for the cover: one frame of $path at $second.
     *
     * @return list<string>
     */
    public function coverCommand(string $path, float $second, string $output): array
    {
        return [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error', '-ss', self::number(max(0.0, $second)), '-i', $path, '-frames:v', '1', '-q:v', '2', $output];
    }

    /**
     * What ffprobe measures of a film or a picture.
     *
     * @return array{duration: float, width: int, height: int, size: int, mime: string, audio: bool}
     */
    public function probe(string $path): array
    {
        if (!is_file($path)) {
            throw new RenderingException(\sprintf('"%s" is not there.', $path));
        }
        $output = $this->run([$this->ffprobe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path], 'The measuring', $this->ffprobe);

        return self::measures((array) json_decode($output, true), $path);
    }

    /**
     * ffprobe's JSON read: the first video stream's size (turned when the
     * film is - a phone's portrait film is stored lying down), the length,
     * the weight, whether there is sound.
     *
     * @return array{duration: float, width: int, height: int, size: int, mime: string, audio: bool}
     */
    public static function measures(array $json, string $path = ''): array
    {
        $width = $height = 0;
        $audio = false;
        $duration = (float) ($json['format']['duration'] ?? 0);
        foreach ($json['streams'] ?? [] as $stream) {
            if ('audio' === ($stream['codec_type'] ?? null)) {
                $audio = true;
            }
            if ('video' !== ($stream['codec_type'] ?? null) || $width) {
                continue;
            }
            $width = (int) ($stream['width'] ?? 0);
            $height = (int) ($stream['height'] ?? 0);
            $rotation = (int) ($stream['tags']['rotate'] ?? 0);
            foreach ($stream['side_data_list'] ?? [] as $side) {
                $rotation = (int) ($side['rotation'] ?? $rotation);
            }
            if (90 === abs($rotation) % 180) {
                [$width, $height] = [$height, $width];
            }
            $duration = $duration ?: (float) ($stream['duration'] ?? 0);
        }
        $format = (string) ($json['format']['format_name'] ?? '');
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        $mime = match (true) {
            str_contains($format, 'webm') && 'webm' === $extension => 'video/webm',
            str_contains($format, 'matroska') => 'webm' === $extension ? 'video/webm' : 'video/x-matroska',
            str_contains($format, 'mp4') => 'mov' === $extension ? 'video/quicktime' : 'video/mp4',
            str_contains($format, 'png') => 'image/png',
            str_contains($format, 'jpeg') || ('image2' === $format && \in_array($extension, ['jpg', 'jpeg'], true)) => 'image/jpeg',
            str_contains($format, 'webp') => 'image/webp',
            str_contains($format, 'gif') => 'image/gif',
            default => 'application/octet-stream',
        };

        return [
            'duration' => str_starts_with($mime, 'image/') ? 0.0 : $duration,
            'width' => $width,
            'height' => $height,
            'size' => (int) ($json['format']['size'] ?? ('' !== $path && is_file($path) ? filesize($path) : 0)),
            'mime' => $mime,
            'audio' => $audio,
        ];
    }

    /**
     * A text (or a path) as a filter's option inside -filter_complex: first
     * what the option's own reading takes (\ ' :), then what the graph's
     * takes (\ ' [ ] , ;). drawtext is told expansion=none, so % and the
     * rest stay what they are.
     */
    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\n", "\r"], ' ', $text);
        $text = strtr($text, ['\\' => '\\\\', "'" => "\\'", ':' => '\\:']);

        return strtr($text, ['\\' => '\\\\', "'" => "\\'", '[' => '\\[', ']' => '\\]', ',' => '\\,', ';' => '\;']);
    }

    /**
     * The band's font size: FONT_SIZE, smaller for a long text so that it
     * holds between the margins (a glyph is about 0.58 of the size wide in
     * a sans), MIN_FONT_SIZE at the least.
     */
    public static function fontSize(string $text): int
    {
        $length = max(1, mb_strlen($text));

        return max(self::MIN_FONT_SIZE, min(self::FONT_SIZE, (int) floor((self::WIDTH - 2 * self::MARGIN) / (0.58 * $length))));
    }

    /** "#rrggbb" (or "#rrggbbaa") as ffmpeg writes a colour: 0xrrggbb. Anything else: black. */
    public static function color(string $hex): string
    {
        return preg_match('/^#?([0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?)$/', trim($hex), $m) ? '0x'.strtolower($m[1]) : '0x000000';
    }

    /** The source brought to the frame: cropped to fill it, or whole on the background. */
    private static function frame(Fit $fit, string $background, int $fps): string
    {
        $size = self::WIDTH.':'.self::HEIGHT;

        return (Fit::COVER === $fit
            ? "scale=$size:force_original_aspect_ratio=increase,crop=$size"
            : "scale=$size:force_original_aspect_ratio=decrease,pad=$size:(ow-iw)/2:(oh-ih)/2:color=".self::color($background)
        ).",setsar=1,fps=$fps";
    }

    /** A silence as long as the pictures it goes under: concat wants sound in every part. */
    private static function silence(?float $duration, string $label): string
    {
        if (null === $duration || $duration <= 0) {
            throw new \InvalidArgumentException('A film without sound needs its duration: the silence laid under it lasts as long.');
        }

        return \sprintf('anullsrc=channel_layout=stereo:sample_rate=48000,atrim=duration=%s[%s]', self::number($duration), $label);
    }

    /** 0.5, 12, 7.25: a number as ffmpeg reads it, whatever the locale. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** @return array{path: string, duration: float, audio: bool}|null */
    private function clip(?string $path): ?array
    {
        if (null === $path) {
            return null;
        }
        $probe = $this->probe($path);

        return ['path' => $path, 'duration' => $probe['duration'], 'audio' => $probe['audio']];
    }

    private function cover(string $path, float $second, string $output): void
    {
        try {
            $this->run($this->coverCommand($path, $second, $output), 'The cover');
        } catch (RenderingException $e) {
            // A film without its still goes out all the same: the network takes a frame itself.
            if (str_contains($e->getMessage(), 'was not found')) {
                throw $e;
            }
        }
    }

    /** @param list<string> $command */
    private function run(array $command, string $what, ?string $binary = null): string
    {
        $binary ??= $this->ffmpeg;
        $found = str_contains($binary, '/') ? is_executable($binary) : null !== (new ExecutableFinder())->find($binary);
        if (!$found) {
            throw RenderingException::missing($binary);
        }

        $process = new Process($command);
        $process->setTimeout($this->timeout);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new RenderingException(\sprintf('%s failed: %s', $what, $e->getMessage()), 0, $e);
        }
        if (127 === $process->getExitCode()) {
            throw RenderingException::missing($binary);
        }
        if (!$process->isSuccessful()) {
            throw RenderingException::failed($what, $process->getErrorOutput() ?: $process->getOutput());
        }

        return $process->getOutput();
    }
}
