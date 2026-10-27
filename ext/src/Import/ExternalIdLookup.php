<?php

/*
 * ExternalIdLookup.php
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

namespace FireflyIII\Ext\Import;

use FireflyIII\Models\UserGroup;
use Illuminate\Support\Facades\DB;

/**
 * Finds journals carrying an external_id in a user group, INCLUDING soft-deleted journals and meta rows:
 * a transaction the user deleted never comes back. Upstream never enforces external_id uniqueness.
 */
final class ExternalIdLookup
{
    public function __construct(private readonly UserGroup $userGroup) {}

    /**
     * @return null|array{journal_id: int, group_id: int, deleted: bool}
     */
    public function find(string $externalId): ?array
    {
        return $this->findMany([$externalId])[$externalId] ?? null;
    }

    /**
     * @param list<string> $externalIds
     *
     * @return array<string, array{journal_id: int, group_id: int, deleted: bool}>
     */
    public function findMany(array $externalIds): array
    {
        $byHash = [];
        foreach (array_unique($externalIds) as $id) {
            $json                                = (string) json_encode($id);
            $byHash[hash('sha256', $json)]       = ['id' => $id, 'json' => $json];
        }
        $result = [];
        foreach (array_chunk(array_keys($byHash), 500) as $hashes) {
            $rows = DB::table('journal_meta')
                ->join('transaction_journals', 'transaction_journals.id', '=', 'journal_meta.transaction_journal_id')
                ->where('transaction_journals.user_group_id', $this->userGroup->id)
                ->where('journal_meta.name', 'external_id')
                ->whereIn('journal_meta.hash', $hashes)
                ->orderBy('transaction_journals.id')
                ->get(['journal_meta.hash', 'journal_meta.data', 'transaction_journals.id as journal_id', 'transaction_journals.transaction_group_id as group_id', 'transaction_journals.deleted_at as journal_deleted', 'journal_meta.deleted_at as meta_deleted']);
            foreach ($rows as $row) {
                $wanted = $byHash[$row->hash] ?? null;
                if (null === $wanted || $wanted['json'] !== (string) $row->data || array_key_exists($wanted['id'], $result)) {
                    continue;
                }
                $result[$wanted['id']] = ['journal_id' => (int) $row->journal_id, 'group_id' => (int) $row->group_id, 'deleted' => null !== $row->journal_deleted || null !== $row->meta_deleted];
            }
        }

        return $result;
    }

    public function exists(string $externalId): bool
    {
        return null !== $this->find($externalId);
    }
}
