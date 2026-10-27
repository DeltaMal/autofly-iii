<?php

/*
 * StatementFile.php
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

namespace FireflyIII\Ext\Statements;

use Closure;

/**
 * An uploaded statement. The text is extracted lazily (pdftotext for PDFs) and only once.
 * The password is used for extraction only and never stored or logged.
 */
final class StatementFile
{
    private ?string $text = null;

    /**
     * @param null|Closure(self): string $extractor defaults to PdfTextExtractor for PDFs, the raw file otherwise
     */
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        #[\SensitiveParameter]
        public readonly ?string $password = null,
        private readonly ?Closure $extractor = null,
    ) {}

    /** A file whose text is already known (tests, pre-extracted layouts). */
    public static function fromText(string $text, string $mime = 'application/pdf', string $path = ''): self
    {
        $file       = new self($path, $mime);
        $file->text = $text;

        return $file;
    }

    public function text(): string
    {
        if (null === $this->text) {
            $this->text = null !== $this->extractor
                ? ($this->extractor)($this)
                : ('application/pdf' === $this->mime ? (new PdfTextExtractor((string) config('ext.statements.pdftotext', 'pdftotext')))->extract($this->path, $this->password) : (string) file_get_contents($this->path));
        }

        return $this->text;
    }

    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'mime' => $this->mime, 'password' => null === $this->password ? null : '***'];
    }
}
