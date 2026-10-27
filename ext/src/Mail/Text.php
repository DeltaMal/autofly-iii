<?php

/*
 * Text.php
 * Copyright (c) 2026 the ext fork authors. Added for the ext fork of Firefly III
 * (https://github.com/DeltaMal/autofly-iii), based on Firefly III by james@firefly-iii.org.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Ext\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Exact ports of the Python importer's text.py (ext/docs/06 section 3).
 */
final class Text
{
    private const string SMILEYS = '/[\x{263A}\x{1F642}\x{1F60A}\x{1F600}]/u';
    private const string SPACES  = '/[\x{A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}\x{2022}\x{25CF}\x{B7}\t\r\f\x{0B}]/u';
    private const array BLOCKS   = ['br', 'p', 'div', 'li', 'tr', 'table', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol'];

    public static function normaliseLine(string $line): string
    {
        $line = (string) preg_replace(self::SMILEYS, ':-)', $line);
        $line = (string) preg_replace(self::SPACES, ' ', $line);
        $line = (string) preg_replace('/ {2,}/', ' ', $line);

        return self::strip($line);
    }

    public static function normaliseText(string $text): string
    {
        $lines = [];
        foreach (self::splitLines($text) as $line) {
            $line = self::normaliseLine($line);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * BeautifulSoup(html.parser): drop script/style/head, put "\n" before and after every block tag, take the
     * text (entities decoded, comments excluded), then normaliseText().
     */
    public static function htmlToText(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath    = new DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//script|//style|//head') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (self::BLOCKS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) {
                /** @var DOMElement $element */
                $element->parentNode?->insertBefore($document->createTextNode("\n"), $element);
                if (null !== $element->nextSibling) {
                    $element->parentNode?->insertBefore($document->createTextNode("\n"), $element->nextSibling);
                } else {
                    $element->parentNode?->appendChild($document->createTextNode("\n"));
                }
            }
        }
        $text     = '';
        foreach ($document->childNodes as $child) {
            $text .= self::textOf($child);
        }

        return self::normaliseText($text);
    }

    /** Python str.strip(): all Unicode whitespace. */
    public static function strip(string $text): string
    {
        return (string) preg_replace('/^[\s\p{Z}\x{1C}-\x{1F}\x{85}]+|[\s\p{Z}\x{1C}-\x{1F}\x{85}]+$/u', '', $text);
    }

    /**
     * Python str.splitlines().
     *
     * @return list<string>
     */
    public static function splitLines(string $text): array
    {
        $parts = preg_split('/\r\n|[\n\r\x{0B}\x{0C}\x{1C}\x{1D}\x{1E}\x{85}\x{2028}\x{2029}]/u', $text) ?: [];
        if ([] !== $parts && '' === $parts[count($parts) - 1]) {
            array_pop($parts);
        }

        return $parts;
    }

    private static function textOf(DOMNode $node): string
    {
        if (XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType) {
            return (string) $node->nodeValue;
        }
        if (XML_ELEMENT_NODE !== $node->nodeType && XML_DOCUMENT_NODE !== $node->nodeType && XML_HTML_DOCUMENT_NODE !== $node->nodeType) {
            return ''; // comments, processing instructions, doctype
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::textOf($child);
        }

        return $text;
    }
}
