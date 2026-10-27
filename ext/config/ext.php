<?php

/*
 * ext.php
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

return [
    // Version of the ext layer, appended to the upstream version in image tags (6.7.9-ext.N).
    'version'    => '1',

    // AGPL-3.0 section 13: where users of this instance can get the modified source.
    'source_url' => env('EXT_SOURCE_URL', 'https://github.com/DeltaMal/autofly-iii/tree/ext'),

    // Supported banks (FireflyIII\Ext\Banks\BankDefinition), keyed by id.
    'banks'      => [
        'fnb' => FireflyIII\Ext\Banks\Fnb\FnbBank::class,
    ],

    // Statement import (ext/docs/02).
    'statements' => [
        'max_upload_bytes' => 10 * 1024 * 1024,
        'storage_disk'     => 'upload',
        'storage_dir'      => 'ext/statements',
        'pdftotext'        => env('EXT_PDFTOTEXT', 'pdftotext'),
    ],
];
