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

use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Console\DevSeed;
use FireflyIII\Ext\Http\Middleware\SaveExtPeriodPreference;
use FireflyIII\Ext\Periods\ExtNavigation;
use FireflyIII\Ext\Periods\PeriodSettings;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
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

        // Phase 1: custom periods. Registered after FireflyServiceProvider, so this binding wins.
        $this->app->bind('navigation', static fn (): ExtNavigation => new ExtNavigation());

        // Phase 2: banks
        $this->app->singleton(BankRegistry::class, static fn (): BankRegistry => BankRegistry::fromConfig((array) config('ext.banks', [])));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(self::ROOT.'/routes/web.php');
        $this->loadViewsFrom(self::ROOT.'/resources/views', 'ext');
        $this->loadTranslationsFrom(self::ROOT.'/resources/lang', 'ext');
        $this->loadMigrationsFrom(self::ROOT.'/database/migrations');

        $this->bootPeriods();

        if ($this->app->runningInConsole()) {
            $this->commands([DevSeed::class]);
        }
    }

    /**
     * The view range "CP" (ext/docs/01-custom-periods.md).
     */
    private function bootPeriods(): void
    {
        $ranges = config('firefly.valid_view_ranges', []);
        if (!in_array(ExtNavigation::KEY, $ranges, true)) {
            config(['firefly.valid_view_ranges' => [...$ranges, ExtNavigation::KEY]]);
        }
        config([
            'firefly.range_to_repeat_freq.'.ExtNavigation::KEY       => ExtNavigation::KEY,
            'firefly.default_preferences.'.ExtNavigation::PREFERENCE => PeriodSettings::defaults()->toArray(),
        ]);
        $this->pushMiddlewareToGroup('user-full-auth', SaveExtPeriodPreference::class);
    }

    /**
     * Like Router::pushMiddlewareToGroup(), but on the HTTP kernel: the kernel re-syncs its groups to the
     * router when it is constructed, which (in tests, queue workers, ...) can happen after this provider boots.
     */
    private function pushMiddlewareToGroup(string $group, string $middleware): void
    {
        $push = static function (HttpKernel $kernel) use ($group, $middleware): void {
            if ($kernel instanceof Kernel && !in_array($middleware, $kernel->getMiddlewareGroups()[$group] ?? [], true)) {
                $kernel->appendMiddlewareToGroup($group, $middleware);
            }
        };
        if ($this->app->resolved(HttpKernel::class)) {
            $push($this->app->make(HttpKernel::class));
        }
        $this->app->afterResolving(HttpKernel::class, $push);
    }
}
