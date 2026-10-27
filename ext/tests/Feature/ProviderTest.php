<?php

/*
 * ProviderTest.php
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

namespace FireflyIII\Ext\Tests\Feature;

use FireflyIII\Ext\ExtServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ExtServiceProvider::class)]
final class ProviderTest extends FeatureTestCase
{
    public function testProviderIsRegisteredAfterFireflyServiceProvider(): void
    {
        $providers = require __DIR__.'/../../../bootstrap/providers.php';
        $ext       = array_search(ExtServiceProvider::class, $providers, true);
        $firefly   = array_search(\FireflyIII\Providers\FireflyServiceProvider::class, $providers, true);

        self::assertNotFalse($ext);
        self::assertGreaterThan($firefly, $ext);
        self::assertNotNull($this->app->getProvider(ExtServiceProvider::class));
    }

    public function testConfigViewsAndTranslationsAreLoaded(): void
    {
        self::assertSame('https://github.com/DeltaMal/autofly-iii/tree/ext', config('ext.source_url'));
        self::assertTrue(view()->exists('ext::partials.menu'));
        self::assertSame('Bank integrations', __('ext::menu.header', [], 'en_US'));
    }

    public function testSidebarShowsExtMenuAndSourceLink(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->createUser())->get(route('preferences.index'));

        $response->assertOk();
        $response->assertSee('Bank integrations');
        $response->assertSee('https://github.com/DeltaMal/autofly-iii/tree/ext', false);
    }
}
