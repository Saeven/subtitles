<?php

declare(strict_types=1);

namespace Circlical\Subtitles\Converters;

use Carbon\CarbonInterval;
use Circlical\Subtitles\Exception\InvalidSubtitleContentsException;
use Circlical\Subtitles\Exception\InvalidTimeFormatException;
use Circlical\Subtitles\Providers\ConstantsInterface;
use Circlical\Subtitles\Providers\ConverterInterface;
use Closure;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function preg_match;
use function preg_replace;
use function preg_split;
use function sprintf;
use function str_replace;
use function strpos;
use function substr;
use function trim;

class VttConverter implements ConverterInterface
{
    private const PERCENTAGE_PATTERN = '0*(?:100(?:\.0+)?|[0-9]{1,2}(?:\.[0-9]+)?)%';

    private const CUE_SETTING_PATTERNS = [
        'align' => '(?:start|center|end|left|right)',
        'line' => '(?:-?[0-9]+|' . self::PERCENTAGE_PATTERN . ')(?:,(?:start|center|end))?',
        'position' => self::PERCENTAGE_PATTERN . '(?:,(?:line-left|center|line-right))?',
        'region' => '(?!.*-->)[^\x09\x0A\x0C\x0D\x20]+',
        'size' => self::PERCENTAGE_PATTERN,
        'vertical' => '(?:rl|lr)',
    ];

    /** Parse cue timings, text, and spec-valid WebVTT cue settings. */
    public function parseSubtitles(string $fileContent): array
    {
        $internalFormat = [];
        $fileContent = preg_replace('/\n\n+/', "\n\n", $fileContent);
        $blocks = explode("\n\n", trim($fileContent));

        foreach ($blocks as $block) {
            if (preg_match('/^WEBVTT.{0,}/', $block, $matches)) {
                continue;
            }

            $lines = explode("\n", $block); // separate all block lines

            if (strpos($lines[0], '-->') === false) { // first line not containing '-->', must be cue id
                unset($lines[0]); // not supporting cue id
                $lines = array_values($lines);
            }

            if (empty($lines[0]) || strpos($lines[0], '-->') === false) {
                throw new InvalidSubtitleContentsException();
            }

            if (preg_match('/\A(?<start>[^ \t]+)[ \t]+-->[ \t]+(?<end>[^ \t]+)(?:[ \t]+(?<settings>.*))?\z/', $lines[0], $timing) !== 1) {
                throw new InvalidSubtitleContentsException('Invalid WebVTT cue timing line: ' . $lines[0]);
            }

            $start = $this->toInternalTimeFormat($timing['start']);
            $end = $this->toInternalTimeFormat($timing['end']);
            $settings = $this->normalizeCueSettings($timing['settings'] ?? '');

            if ($end <= $start) {
                throw new InvalidSubtitleContentsException('A WebVTT cue must end after its start time.');
            }

            $linesArray = array_map(static::fixLine(), array_slice($lines, 1)); // get all the remaining lines from block (if multiple lines of text)
            if (count($linesArray) === 0) {
                continue;
            }

            $cue = [
                'start' => $start,
                'end' => $end,
                'lines' => $linesArray,
            ];

            if ($settings !== '') {
                $cue['settings'] = $settings;
            }

            $internalFormat[] = $cue;
        }

        return $internalFormat;
    }

    /** Write WebVTT while retaining validated cue settings when present. */
    public function toSubtitles(array $internalFormat): string
    {
        $fileContent = "WEBVTT\n\n";

        foreach ($internalFormat as $k => $block) {
            $start = $this->toSubtitleTimeFormat($block['start']);
            $end = $this->toSubtitleTimeFormat($block['end']);
            $lines = implode("\n", $block['lines']);
            $settings = $this->normalizeCueSettings($block['settings'] ?? '');

            $fileContent .= $start . ' --> ' . $end;
            if ($settings !== '') {
                $fileContent .= ' ' . $settings;
            }

            $fileContent .= "\n";
            $fileContent .= $lines . "\n";
            $fileContent .= "\n";
        }

        return trim($fileContent);
    }

    /** Validate authoring syntax, rather than silently ignoring invalid settings as a browser would. */
    private function normalizeCueSettings(string $settings): string
    {
        $tokens = preg_split('/[ \t]+/', $settings, -1, PREG_SPLIT_NO_EMPTY);
        $seen = [];

        foreach ($tokens as $token) {
            $parts = explode(':', $token, 2);
            $name = $parts[0];

            if (!isset($parts[1], self::CUE_SETTING_PATTERNS[$name]) || isset($seen[$name])) {
                throw new InvalidSubtitleContentsException('Invalid or repeated WebVTT cue setting: ' . $token);
            }

            if (preg_match('/\A' . self::CUE_SETTING_PATTERNS[$name] . '\z/', $parts[1]) !== 1) {
                throw new InvalidSubtitleContentsException('Invalid WebVTT cue setting: ' . $token);
            }

            $seen[$name] = true;
        }

        return implode(' ', $tokens);
    }

    protected static function fixLine(): Closure
    {
        return function ($line) {
            if (substr($line, 0, 3) === '<v ') {
                $line = substr($line, 3);
                $line = str_replace('>', ' ', $line);
            }

            return $line;
        };
    }

    /**
     * 00:00:00.500 --> xx.yyy
     *
     * @throws InvalidTimeFormatException
     */
    public function toInternalTimeFormat(string $subtitleFormat): float
    {
        $matchResult = preg_match('/\A(?:(?<hours>[0-9]{2,}):)?(?<minutes>[0-5][0-9]):(?<seconds>[0-5][0-9])\.(?<fraction>[0-9]{3})\z/', $subtitleFormat, $matches);
        if ($matchResult !== 1) {
            throw new InvalidTimeFormatException($subtitleFormat);
        }

        if (!isset($matches['hours'])) {
            $matches['hours'] = 0;
        }

        return (int) $matches['hours'] * ConstantsInterface::HOURS_SECONDS
            + (int) $matches['minutes'] * ConstantsInterface::MINUTES_SECONDS
            + (int) $matches['seconds']
            + (float) $matches['fraction'] / 1000;
    }

    /**
     * xx.yyy -> 00:00:00.500
     */
    public function toSubtitleTimeFormat(float $internalFormat): string
    {
        $interval = CarbonInterval::createFromFormat("s.u", sprintf("%.3F", $internalFormat))->cascade();

        return sprintf(
            "%02d:%02d:%02d.%03d",
            $interval->hours,
            $interval->minutes,
            $interval->seconds,
            $interval->milliseconds
        );
    }
}
