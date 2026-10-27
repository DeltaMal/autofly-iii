<?php

/*
 * TouchpointsTest.php
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

namespace FireflyIII\Ext\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every hook listed in ext/UPSTREAM_TOUCHPOINTS.md must still be present upstream.
 *
 * @internal
 */
#[CoversNothing]
final class TouchpointsTest extends TestCase
{
    public function testEveryTouchpointMarkerIsPresent(): void
    {
        $root   = dirname(__DIR__, 3);
        $output = [];
        $code   = 0;
        exec(sprintf('bash %s 2>&1', escapeshellarg($root.'/ext/scripts/check-touchpoints.sh')), $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
    }
}
