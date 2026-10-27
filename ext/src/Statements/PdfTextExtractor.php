<?php

/*
 * PdfTextExtractor.php
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

use Symfony\Component\Process\Process;

/**
 * pdftotext -layout (poppler-utils, installed in the ext Docker image). No shell: the command is an
 * argument array, with a 30 s timeout and a 10 MB file limit. The password is passed once, never logged.
 */
final class PdfTextExtractor
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly string $binary = 'pdftotext', private readonly int $timeout = 30) {}

    public function extract(string $path, #[\SensitiveParameter] ?string $password = null): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new StatementException('The statement file cannot be read.');
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new StatementException('The statement file is larger than 10 MB.');
        }
        $command = [$this->binary, '-layout', '-enc', 'UTF-8'];
        if (null !== $password && '' !== $password) {
            array_push($command, '-upw', $password);
        }
        array_push($command, $path, '-');
        $process = new Process($command, null, ['LANG' => 'C.UTF-8']);
        $process->setTimeout($this->timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            if (str_contains(strtolower($error), 'password')) {
                throw new StatementException('The PDF is password protected; the password is missing or wrong.');
            }

            throw new StatementException('pdftotext could not read the PDF.');
        }

        return $process->getOutput();
    }
}
