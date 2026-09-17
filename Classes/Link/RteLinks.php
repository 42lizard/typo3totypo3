<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Masterminds\HTML5\Parser\DOMTreeBuilder;
use Masterminds\HTML5\Parser\Scanner;
use Masterminds\HTML5\Parser\Tokenizer;

/** HTML5 token offsets let us change href attributes without reserializing editorial HTML. */
final class RteLinks
{
    public static function anchors(string $html): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            return [];
        }
        $parser = new class(new Scanner($html), new DOMTreeBuilder(true)) extends Tokenizer {
            public array $anchors = [];
            public bool $invalid = false;
            private bool $anchor = false;

            protected function tagName()
            {
                $position = $this->scanner->position();
                $name = $this->scanner->charsWhile(':_-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
                $this->scanner->unconsume($this->scanner->position() - $position);
                $this->anchor = strtolower($name) === 'a';
                return parent::tagName();
            }

            protected function attribute(&$attributes)
            {
                $start = $this->scanner->position();
                $hadHref = array_key_exists('href', $attributes);
                $result = parent::attribute($attributes);
                if ($this->anchor && !$hadHref && is_string($attributes['href'] ?? null)) {
                    $this->anchors[] = ['url' => $attributes['href'], 'start' => $start, 'length' => $this->scanner->position() - $start];
                }
                return $result;
            }

            protected function parseError($msg, ...$args)
            {
                $this->invalid = true;
                return parent::parseError($msg, ...$args);
            }
        };
        $parser->parse();
        if ($parser->invalid) {
            return [];
        }
        // Scanner normalizes CRLF. Map its byte offsets back to the unchanged source.
        preg_match_all('/\r\n/', $html, $newlines, PREG_OFFSET_CAPTURE);
        $offset = static function (int $position) use ($newlines): int {
            $extra = 0;
            foreach ($newlines[0] as [, $original]) {
                if ($original - $extra >= $position) {
                    break;
                }
                ++$extra;
            }
            return $position + $extra;
        };
        return array_map(static function (array $anchor) use ($offset): array {
            $end = $offset($anchor['start'] + $anchor['length']);
            $anchor['start'] = $offset($anchor['start']);
            $anchor['length'] = $end - $anchor['start'];
            return $anchor;
        }, $parser->anchors);
    }

    public static function replace(string $html, array $anchors, array $urls): string
    {
        foreach (array_reverse($anchors, true) as $index => $anchor) {
            if (isset($urls[$index])) {
                $html = substr_replace($html, 'href="' . htmlspecialchars($urls[$index], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"', $anchor['start'], $anchor['length']);
            }
        }
        return $html;
    }
}
