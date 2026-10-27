<?php

/*
 * ExtServiceProvider.php
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

namespace FireflyIII\Ext;

use Illuminate\Support\ServiceProvider;

/**
 * The single entry point of the ext fork. Everything custom is wired in here,
 * so upstream files only need the one-line hooks in ext/UPSTREAM_TOUCHPOINTS.md.
 */
final class ExtServiceProvider extends ServiceProvider
{
    public const string ROOT = __DIR__.'/..';

    public function register(): void
    {
        $this->mergeConfigFrom(self::ROOT.'/config/ext.php', 'ext');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(self::ROOT.'/routes/web.php');
        $this->loadViewsFrom(self::ROOT.'/resources/views', 'ext');
        $this->loadTranslationsFrom(self::ROOT.'/resources/lang', 'ext');
        $this->loadMigrationsFrom(self::ROOT.'/database/migrations');
    }
}
