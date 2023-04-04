<?php

declare(strict_types=1);

namespace Done\Subtitles\Converters;

use function explode;
use function floor;
use function gmdate;
use function implode;
use function preg_match_all;
use function str_pad;
use function str_replace;
use function strtotime;
use function substr;

use const PREG_SET_ORDER;
use const STR_PAD_RIGHT;

class DfxpConverter implements ConverterInterface
{
    public function fileContentToInternalFormat(string $fileContent)
    {
        preg_match_all('/<p.+begin="(?<start>[^"]+).*end="(?<end>[^"]+)[^>]*>(?<text>(?!<\/p>).+)<\/p>/', $fileContent, $matches, PREG_SET_ORDER);

        $internal_format = [];
        foreach ($matches as $block) {
            $internal_format[] = [
                'start' => static::dfxpTimeToInternal($block['start']),
                'end' => static::dfxpTimeToInternal($block['end']),
                'lines' => explode('<br/>', $block['text']),
            ];
        }

        return $internal_format;
    }

    public function internalFormatToFileContent(array $internalFormat)
    {
        $file_content = '<?xml version="1.0" encoding="utf-8"?>
<tt xmlns="http://www.w3.org/ns/ttml" xmlns:ttm="http://www.w3.org/ns/ttml#metadata" xmlns:tts="http://www.w3.org/ns/ttml#styling" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <head>
    <metadata>
      <ttm:title>Netflix Subtitle</ttm:title>
    </metadata>
    <styling>
      <style tts:fontStyle="normal" tts:fontWeight="normal" xml:id="s1" tts:color="white" tts:fontFamily="Arial" tts:fontSize="100%"></style>
    </styling>
    <layout>
      <region tts:extent="80% 40%" tts:origin="10% 10%" tts:displayAlign="before" tts:textAlign="center" xml:id="topCenter" />
      <region tts:extent="80% 40%" tts:origin="10% 50%" tts:displayAlign="after" tts:textAlign="center" xml:id="bottomCenter" />
    </layout>
  </head>
  <body>
    <div style="s1" xml:id="d1">
';

        foreach ($internalFormat as $k => $block) {
            $nr = $k + 1;
            $start = static::internalTimeToDfxp($block['start']);
            $end = static::internalTimeToDfxp($block['end']);
            $lines = implode("<br/>", $block['lines']);

            $file_content .= "    <p xml:id=\"p{$nr}\" begin=\"{$start}\" end=\"{$end}\" region=\"bottomCenter\">{$lines}</p>\n";
        }

        $file_content .= '  </div>
  </body>
</tt>';

        $file_content = str_replace("\r", "", $file_content);
        $file_content = str_replace("\n", "\r\n", $file_content);

        return $file_content;
    }

    // ---------------------------------- private ----------------------------------------------------------------------

    protected static function internalTimeToDfxp(string $internalTime)
    {
        $parts = explode('.', $internalTime); // 1.23
        $whole = $parts[0]; // 1
        $decimal = isset($parts[1]) ? substr($parts[1], 0, 3) : 0; // 23

        return gmdate("H:i:s", (int) floor($whole)) . ',' . str_pad($decimal, 3, '0', STR_PAD_RIGHT);
    }

    protected static function dfxpTimeToInternal(string $dfxpTime)
    {
        $parts = explode(',', $dfxpTime);

        $only_seconds = strtotime("1970-01-01 {$parts[0]} UTC");
        $milliseconds = (float) '0.' . $parts[1];

        return $only_seconds + $milliseconds;
    }
}
