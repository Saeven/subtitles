<?php

declare(strict_types=1);

use Circlical\Subtitles\Exception\InvalidSubtitleContentsException;
use Circlical\Subtitles\Exception\InvalidTimeFormatException;
use Circlical\Subtitles\Subtitles;
use PHPUnit\Framework\TestCase;

class VttTest extends TestCase
{
    use AdditionalAssertions;

    public function testConvertFromVttToSrt()
    {
        $vttContent = file_get_contents('./tests/files/vtt.vtt');
        $srtContent = file_get_contents('./tests/files/srt.srt');

        $actual = (new Subtitles())->load($vttContent, 'vtt')->content('srt');
        $this->assertEquals($actual, $srtContent);
    }

    public function testConvertFromSrtToVtt()
    {
        $vttContent = file_get_contents('./tests/files/vtt.vtt');
        $srtContent = file_get_contents('./tests/files/srt.srt');

        $actual = (new Subtitles())->load($srtContent, 'srt')->content('vtt');
        $this->assertEquals($vttContent, $actual);
    }

    public function testFileToInternalFormat()
    {
        $expected = [
            [
                'start' => 9.0,
                'end' => 11.0,
                'lines' => ['Roger Bingham We are in New York City'],
            ],
        ];

        $internalformat = Subtitles::load(file_get_contents('./tests/files/vtt_with_name.vtt'), 'vtt')->getInternalFormat();
        $this->assertInternalFormatsEqual($expected, $internalformat);
    }

    public function timeTest()
    {
        $converter = new VttConverter();
        $internalFormat = $converter->toInternalTimeFormat('00:00:11.000');
        $this->assertEquals(11.0, $internalFormat);
    }

    public function testConvertToInternalFormatWhenFileContainsNumbers() // numbers are optional in webvtt format
    {
        $inputVttContent = <<<TEXT
WEBVTT

1
00:00:09.000 --> 00:00:11.000
Roger Bingham We are in New York City
TEXT;

        $expectedVttContent = <<<TEXT
WEBVTT

00:00:09.000 --> 00:00:11.000
Roger Bingham We are in New York City
TEXT;

        $generatedVttContent = (new Subtitles())->load($inputVttContent, 'vtt')->content('vtt');
        $this->assertEquals($expectedVttContent, $generatedVttContent);
    }

    public function testParsesFileWithMissingText()
    {
        $vttContent = file_get_contents('./tests/files/vtt_with_missing_text.vtt');
        $actual = (new Subtitles())->load($vttContent, 'vtt')->getInternalFormat();
        $expected = [
            [
                'start' => 0,
                'end' => 1,
                'lines' => [
                    'one',
                ],
                [
                    'start' => 2,
                    'end' => 3,
                    'lines' => [
                        'three',
                    ],
                ],
            ],
        ];
        $this->assertInternalFormatsEqual($expected, $actual);
    }

    public function testFileContainingMultipleNewLinesBetweenBlocks()
    {
        $given = <<<TEXT
WEBVTT

00:00:00.000 --> 00:00:01.000
text1





00:00:01.000 --> 00:00:02.000
text2
TEXT;
        $actual = (new Subtitles())->load($given, 'vtt')->getInternalFormat();

        $expected = (new Subtitles())
            ->add(0, 1, 'text1')
            ->add(1, 2, 'text2')
            ->getInternalFormat();

        $this->assertEquals($expected, $actual);
    }

    public function testThrowsAnExceptionWhenFilesContainGarbage()
    {
        $this->expectException(InvalidSubtitleContentsException::class);
        $vttContent = file_get_contents('./tests/files/garbage.vtt');
        (new Subtitles())->load($vttContent, 'vtt')->getInternalFormat();
    }

    public function testShortTimeFormat()
    {
        $expected = [
            [
                'start' => 1.71,
                'end' => 3.17,
                'lines' => ["Hi, I'm Anna, and I'm here to help."],
            ],
            [
                'start' => 4.19,
                'end' => 9.03,
                'lines' => ["Here, we know it can be challenging to stay on top of all your payments when there are competing"],
            ],
            [
                'start' => 9.03,
                'end' => 10.13,
                'lines' => ["demands for your money."],
            ],
        ];

        $internalformat = Subtitles::load(file_get_contents('./tests/files/vtt_short_times.vtt'), 'vtt')->getInternalFormat();
        $this->assertInternalFormatsEqual($expected, $internalformat);
    }

    /**
     * Caption uploads retain their positioning, text, and timestamps while empty cues are skipped.
     */
    public function testPreservesPositionedTranscriptExcerpt(): void
    {
        $input = <<<'VTT'
WEBVTT

00:00:00.000 --> 00:00:00.976 align:center line:90%


00:00:00.976 --> 00:00:03.904 align:center line:90%
[MUSIC PLAYING]

00:00:03.904 --> 00:00:09.770 align:center line:90%


00:00:09.770 --> 00:00:12.260 align:center line:84%
SPEAKER: U.S. Bank supports
you as you start your new life

00:01:21.380 --> 00:01:25.220 align:center line:84%
or by email at
GTSinfo\@usbank.com.

00:01:33.040 --> 00:01:35.000 align:center line:90%
VTT;

        $expected = <<<'VTT'
WEBVTT

00:00:00.976 --> 00:00:03.904 align:center line:90%
[MUSIC PLAYING]

00:00:09.770 --> 00:00:12.260 align:center line:84%
SPEAKER: U.S. Bank supports
you as you start your new life

00:01:21.380 --> 00:01:25.220 align:center line:84%
or by email at
GTSinfo\@usbank.com.
VTT;

        $subtitles = Subtitles::load($input, 'vtt');

        $this->assertSame([
            [
                'start' => 0.976,
                'end' => 3.904,
                'lines' => ['[MUSIC PLAYING]'],
                'settings' => 'align:center line:90%',
            ],
            [
                'start' => 9.77,
                'end' => 12.26,
                'lines' => ['SPEAKER: U.S. Bank supports', 'you as you start your new life'],
                'settings' => 'align:center line:84%',
            ],
            [
                'start' => 81.38,
                'end' => 85.22,
                'lines' => ['or by email at', 'GTSinfo\@usbank.com.'],
                'settings' => 'align:center line:84%',
            ],
        ], $subtitles->getInternalFormat());
        $this->assertSame($expected, $subtitles->content('vtt'));
        $this->assertSame($expected, Subtitles::load($subtitles->content('vtt'), 'vtt')->content('vtt'));
    }

    /**
     * All cue settings survive time edits and are omitted when converting to other formats.
     */
    public function testPreservesSettingsThroughTimeEditsAndConversions(): void
    {
        $input = "WEBVTT\n\ncaption-one\n"
            . "00:09.000\t -->\t 00:11.000\tvertical:rl  line:-1,end\tposition:50.5%,line-right  size:99.5% align:right region:caption:ja\t\n"
            . 'Caption';
        $settings = 'vertical:rl line:-1,end position:50.5%,line-right size:99.5% align:right region:caption:ja';
        $subtitles = Subtitles::load($input, 'vtt')->shiftTime(2);

        $this->assertSame([
            [
                'start' => 11.0,
                'end' => 13.0,
                'lines' => ['Caption'],
                'settings' => $settings,
            ],
        ], $subtitles->getInternalFormat());
        $this->assertSame("WEBVTT\n\n00:00:11.000 --> 00:00:13.000 " . $settings . "\nCaption", $subtitles->content('vtt'));
        $this->assertSame("1\n00:00:11,000 --> 00:00:13,000\nCaption", $subtitles->content('srt'));
        $this->assertSame("0:00:11.000,0:00:13.000\nCaption", $subtitles->content('sbv'));
    }

    /**
     * Valid percentage boundaries and fractional positioning survive a VTT round trip.
     */
    public function testPreservesPercentageBoundariesAndFractionalPositioning(): void
    {
        $input = <<<'VTT'
WEBVTT

00:00:00.000 --> 00:00:01.000 line:0% position:100%,line-left size:100.0% align:start
First

00:00:01.000 --> 00:00:02.000 line:90.5%,center position:0%,center size:0% align:end vertical:lr
Second
VTT;

        $this->assertSame($input, Subtitles::load($input, 'vtt')->content('vtt'));
    }

    /**
     * Invalid cue settings must fail validation, including on otherwise empty cues.
     *
     * @dataProvider invalidCueSettingsProvider
     */
    public function testRejectsInvalidCueSettings(string $settings, string $payload): void
    {
        $this->expectException(InvalidSubtitleContentsException::class);

        Subtitles::load("WEBVTT\n\n00:00:00.000 --> 00:00:01.000 " . $settings . "\n" . $payload, 'vtt');
    }

    /**
     * Provides invalid authored settings that permissive playback parsers might ignore.
     */
    public function invalidCueSettingsProvider(): array
    {
        return [
            'legacy alignment' => ['align:middle line:90%', 'Caption'],
            'legacy alignment on empty cue' => ['align:middle line:90%', ''],
            'duplicate setting' => ['line:84% line:90%', 'Caption'],
            'percentage exceeds viewport' => ['line:100.1%', 'Caption'],
            'negative percentage' => ['position:-1%', 'Caption'],
            'fractional line number' => ['line:1.5', 'Caption'],
            'invalid position alignment' => ['position:50%,right', 'Caption'],
            'size without percentage' => ['size:90', 'Caption'],
            'invalid writing direction' => ['vertical:horizontal', 'Caption'],
            'unknown setting' => ['color:red', 'Caption'],
            'empty region identifier' => ['region:', 'Caption'],
            'default is not an authored offset' => ['line:auto', 'Caption'],
        ];
    }

    /**
     * Settings must not hide malformed timestamps or invalid cue durations.
     *
     * @dataProvider invalidCueTimingsProvider
     */
    public function testRejectsInvalidCueTimings(string $timings, string $exceptionClass): void
    {
        $this->expectException($exceptionClass);

        Subtitles::load("WEBVTT\n\n" . $timings . " align:center line:90%\nCaption", 'vtt');
    }

    /**
     * Provides invalid timestamp syntax, ranges, and nonpositive cue durations.
     */
    public function invalidCueTimingsProvider(): array
    {
        return [
            'comma timestamp separator' => ['00:00:00,000 --> 00:00:01.000', InvalidTimeFormatException::class],
            'minute out of range' => ['00:60:00.000 --> 01:01:00.000', InvalidTimeFormatException::class],
            'second out of range' => ['00:00.000 --> 00:60.000', InvalidTimeFormatException::class],
            'timestamp suffix' => ['00:00:00.000 --> 00:00:01.000garbage', InvalidTimeFormatException::class],
            'zero duration' => ['00:00:01.000 --> 00:00:01.000', InvalidSubtitleContentsException::class],
            'negative duration' => ['00:00:02.000 --> 00:00:01.000', InvalidSubtitleContentsException::class],
        ];
    }
}
