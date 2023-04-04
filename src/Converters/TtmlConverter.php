<?php

declare(strict_types=1);

namespace Done\Subtitles\Converters;

use function explode;
use function implode;
use function number_format;
use function preg_match_all;
use function rtrim;
use function str_replace;

use const PREG_SET_ORDER;

class TtmlConverter implements ConverterInterface
{
    public function fileContentToInternalFormat(string $fileContent)
    {
        preg_match_all('/<p.+begin="(?<start>[^"]+).*end="(?<end>[^"]+)[^>]*>(?<text>(?!<\/p>).+)<\/p>/', $fileContent, $matches, PREG_SET_ORDER);

        $internal_format = [];
        foreach ($matches as $block) {
            $internal_format[] = [
                'start' => static::ttmlTimeToInternal($block['start']),
                'end' => static::ttmlTimeToInternal($block['end']),
                'lines' => explode('<br />', $block['text']),
            ];
        }

        return $internal_format;
    }

    public function internalFormatToFileContent(array $internalFormat)
    {
        $file_content = '<?xml version="1.0" encoding="utf-8"?>
<tt xmlns="http://www.w3.org/ns/ttml" xmlns:ttp="http://www.w3.org/ns/ttml#parameter" ttp:timeBase="media" xmlns:tts="http://www.w3.org/ns/ttml#style" xml:lang="en" xmlns:ttm="http://www.w3.org/ns/ttml#metadata">
  <head>
    <metadata>
      <ttm:title></ttm:title>
    </metadata>
    <styling>
      <style id="s0" tts:backgroundColor="black" tts:fontStyle="normal" tts:fontSize="16" tts:fontFamily="sansSerif" tts:color="white" />
    </styling>
  </head>
  <body style="s0">
    <div>
';

        foreach ($internalFormat as $k => $block) {
            $start = static::internalTimeToTtml($block['start']);
            $end = static::internalTimeToTtml($block['end']);
            $lines = implode("<br />", $block['lines']);

            $file_content .= "      <p begin=\"{$start}s\" id=\"p{$k}\" end=\"{$end}s\">{$lines}</p>\n";
        }

        $file_content .= '    </div>
  </body>
</tt>';

        $file_content = str_replace("\r", "", $file_content);
        $file_content = str_replace("\n", "\r\n", $file_content);

        return $file_content;
    }

    // ---------------------------------- private ----------------------------------------------------------------------

    protected static function internalTimeToTtml(float $internalTime)
    {
        return number_format($internalTime, 1, '.', '');
    }

    protected static function ttmlTimeToInternal(string $ttmlTime)
    {
        return rtrim($ttmlTime, 's');
    }
}
