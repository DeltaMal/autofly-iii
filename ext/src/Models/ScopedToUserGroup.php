<?php

/*
 * ScopedToUserGroup.php
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

namespace FireflyIII\Ext\Models;

use FireflyIII\Models\UserGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every ext_* query is scoped to the current user's group: Model::query()->forGroup($group).
 */
trait ScopedToUserGroup
{
    public function scopeForGroup(Builder $query, UserGroup|int $group): Builder
    {
        return $query->where($this->getTable().'.user_group_id', $group instanceof UserGroup ? $group->id : $group);
    }

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class);
    }
}
