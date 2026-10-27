<?php

/*
 * web.php
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

use Illuminate\Support\Facades\Route;

// Routes of the ext fork: prefix /ext, names ext.*. Upstream's RouteServiceProvider
// does not wrap these in the "web" group, so it is added explicitly.
Route::group(
    ['middleware' => ['web', 'user-full-auth'], 'prefix' => 'ext', 'as' => 'ext.', 'namespace' => 'FireflyIII\Ext\Http\Controllers'],
    static function (): void {
        // Phase 1: custom periods
        Route::get('periods', ['uses' => 'PeriodController@index', 'as' => 'periods.index']);
        Route::post('periods/close', ['uses' => 'PeriodController@close', 'as' => 'periods.close']);
        Route::post('periods/starts/delete', ['uses' => 'PeriodController@deleteStart', 'as' => 'periods.starts.delete']);

        // Phase 2: statements and auto-recon
        Route::get('statements', ['uses' => 'StatementController@index', 'as' => 'statements.index']);
        Route::get('statements/upload', ['uses' => 'StatementController@create', 'as' => 'statements.create']);
        Route::post('statements', ['uses' => 'StatementController@store', 'as' => 'statements.store']);
        Route::get('statements/{statementId}', ['uses' => 'StatementController@show', 'as' => 'statements.show'])->whereNumber('statementId');
        Route::post('statements/{statementId}/commit', ['uses' => 'StatementController@commit', 'as' => 'statements.commit'])->whereNumber('statementId');
    }
);
