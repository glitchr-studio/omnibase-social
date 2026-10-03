<?php

namespace Base\Social\Tests\Service;

use Base\Social\Enum\Fit;
use Base\Social\Enum\LogoPosition;
use Base\Social\Service\Renderer;
use PHPUnit\Framework\TestCase;

/** The ffmpeg command, read as it is: no file touched, no ffmpeg needed. */
final class RendererCommandTest extends TestCase
{
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new Renderer('ffmpeg', 'ffprobe', 20, 30, 900, '/fonts/DejaVuSans.ttf', '/tmp/social');
    }

    public function testCoverWithLogoBandAndIntro(): void
    {
        $command = $this->renderer->command([
            'source' => '/in/concert.mov',
            'output' => '/out/instagram.mp4',
            'duration' => 42.0,
            'fit' => Fit::COVER,
            'logo' => '/in/logo.png',
            'logoPosition' => LogoPosition::TOP_RIGHT,
            'logoScale' => 0.2,
            'band' => true,
            'bandText' => "Brieuc Vourch, violon: Bach's [Chaconne]",
            'bandColor' => '#1A1A1A',
            'textColor' => '#ffffff',
            'font' => '/fonts/Garamond.ttf',
            'fade' => 0.5,
            'intro' => ['path' => '/in/intro.mp4', 'duration' => 2.0, 'audio' => true],
        ]);

        // Inputs in order: the source, the logo, the intro.
        self::assertSame(['/in/concert.mov', '/in/logo.png', '/in/intro.mp4'], self::inputs($command));
        $graph = self::graph($command);
        $chains = explode(';', $graph);

        self::assertSame('[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,fps=30[base]', $chains[0]);
        self::assertSame('[1:v]scale=216:-1[logo]', $chains[1]);
        self::assertSame('[base][logo]overlay=W-w-64:64[logod]', $chains[2]);
        self::assertStringStartsWith('[logod]drawbox=x=0:y=1380:w=1080:h=140:color=0x1a1a1a:t=fill,drawtext=fontfile=/fonts/Garamond.ttf:', $chains[3]);
        // The text escaped twice: for the option (\' \:), then for the graph (\\ \' \[ \] \,).
        self::assertStringContainsString("text=Brieuc Vourch\\, violon\\\\: Bach\\\\\\'s \\[Chaconne\\]:expansion=none", $chains[3]);
        // 40 characters: the size brought down so that the text holds between the margins.
        self::assertStringContainsString('fontcolor=0xffffff:fontsize=41:x=(w-text_w)/2:y=1380+(140-text_h)/2', $chains[3]);
        self::assertStringEndsWith('fade=t=in:st=0:d=0.5,fade=t=out:st=41.5:d=0.5,format=yuv420p[v]', $chains[3]);
        self::assertSame('[0:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo,afade=t=in:st=0:d=0.5,afade=t=out:st=41.5:d=0.5[a]', $chains[4]);
        self::assertSame('[2:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,fps=30,format=yuv420p[vi]', $chains[5]);
        self::assertSame('[2:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[ai]', $chains[6]);
        self::assertSame('[vi][ai][v][a]concat=n=2:v=1:a=1[vout][aout]', $chains[7]);
        self::assertCount(8, $chains);

        self::assertSame(['[vout]', '[aout]'], self::maps($command));
        $tail = implode(' ', \array_slice($command, array_search('-c:v', $command, true)));
        self::assertSame('-c:v libx264 -profile:v high -level 4.1 -pix_fmt yuv420p -r 30 -crf 20 -c:a aac -b:a 160k -ar 48000 -movflags +faststart /out/instagram.mp4', $tail);
    }

    public function testContainWithOutroWithoutSoundOrLogo(): void
    {
        $command = $this->renderer->command([
            'source' => '/in/landscape.mp4',
            'output' => '/out/youtube.mp4',
            'duration' => 12.0,
            'fit' => Fit::CONTAIN,
            'background' => '#F5F0E6',
            'band' => true,
            'bandText' => 'Anaëlle Tourret',
            'outro' => ['path' => '/in/outro.mp4', 'duration' => 3.0, 'audio' => false],
            'fps' => 25,
        ]);

        self::assertSame(['/in/landscape.mp4', '/in/outro.mp4'], self::inputs($command));
        $chains = explode(';', self::graph($command));

        self::assertSame('[0:v]scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=0xf5f0e6,setsar=1,fps=25[base]', $chains[0]);
        // No logo: the band goes straight on the base; no font uploaded nor configured: fontconfig's Sans.
        self::assertSame('[base]drawbox=x=0:y=1380:w=1080:h=140:color=0x000000:t=fill,drawtext=font=Sans:text=Anaëlle Tourret:expansion=none:fontcolor=0xffffff:fontsize=56:x=(w-text_w)/2:y=1380+(140-text_h)/2,format=yuv420p[v]', $chains[1]);
        self::assertSame('[0:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[a]', $chains[2]);
        // The outro: the same frame (cropped to fill), and a silence as long as it.
        self::assertSame('[1:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,fps=25,format=yuv420p[vo]', $chains[3]);
        self::assertSame('anullsrc=channel_layout=stereo:sample_rate=48000,atrim=duration=3[ao]', $chains[4]);
        self::assertSame('[v][a][vo][ao]concat=n=2:v=1:a=1[vout][aout]', $chains[5]);
        self::assertContains('25', $command);
    }

    public function testPlainCoverHasNoConcatAndMapsTheDressedStreams(): void
    {
        $command = $this->renderer->command(['source' => '/in/a.mp4', 'output' => '/out/b.mp4', 'duration' => 5.0, 'audio' => false, 'logo' => '/in/l.png', 'logoPosition' => LogoPosition::BOTTOM_LEFT, 'logoScale' => 0.9]);
        $graph = self::graph($command);

        self::assertStringNotContainsString('concat', $graph);
        // The scale held to 0.5 of the width at most.
        self::assertStringContainsString('[1:v]scale=540:-1[logo]', $graph);
        self::assertStringContainsString('overlay=64:H-h-64[logod]', $graph);
        self::assertStringContainsString('anullsrc=channel_layout=stereo:sample_rate=48000,atrim=duration=5[a]', $graph);
        self::assertSame(['[v]', '[a]'], self::maps($command));
    }

    public function testCoverCommandAndHelpers(): void
    {
        self::assertSame(['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error', '-ss', '2.5', '-i', '/out/a.mp4', '-frames:v', '1', '-q:v', '2', '/out/a.jpg'], $this->renderer->coverCommand('/out/a.mp4', 2.5, '/out/a.jpg'));
        self::assertSame('0xff8800', Renderer::color('#FF8800'));
        self::assertSame('0x000000', Renderer::color('red'));
        self::assertSame('(W-w)/2:(H-h)/2', LogoPosition::CENTER->overlay(64));
        self::assertSame(56, Renderer::fontSize('Anaëlle Tourret'));
        self::assertSame(28, Renderer::fontSize(str_repeat('a long band text ', 8)));

        $measures = Renderer::measures(['format' => ['duration' => '15.04', 'size' => '1048576', 'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'], 'streams' => [
            ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'side_data_list' => [['rotation' => -90]]],
            ['codec_type' => 'audio'],
        ]], '/in/phone.mp4');
        // A phone's portrait film stored lying down is measured upright.
        self::assertSame(['duration' => 15.04, 'width' => 1080, 'height' => 1920, 'size' => 1048576, 'mime' => 'video/mp4', 'audio' => true], $measures);
    }

    /** @return list<string> */
    private static function inputs(array $command): array
    {
        $inputs = [];
        foreach ($command as $i => $argument) {
            if ('-i' === $argument) {
                $inputs[] = $command[$i + 1];
            }
        }

        return $inputs;
    }

    private static function graph(array $command): string
    {
        return $command[array_search('-filter_complex', $command, true) + 1];
    }

    /** @return list<string> */
    private static function maps(array $command): array
    {
        $maps = [];
        foreach ($command as $i => $argument) {
            if ('-map' === $argument) {
                $maps[] = $command[$i + 1];
            }
        }

        return $maps;
    }
}
