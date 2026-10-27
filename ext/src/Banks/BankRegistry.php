<?php

/*
 * BankRegistry.php
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

namespace FireflyIII\Ext\Banks;

use FireflyIII\Ext\Statements\StatementFile;
use FireflyIII\Ext\Statements\StatementParser;
use InvalidArgumentException;

/**
 * The banks listed in config('ext.banks'), keyed by id.
 */
final class BankRegistry
{
    /** @var array<string, BankDefinition> */
    private array $banks = [];

    /**
     * @param iterable<BankDefinition> $banks
     */
    public function __construct(iterable $banks)
    {
        foreach ($banks as $bank) {
            $this->banks[$bank->id()] = $bank;
        }
    }

    public static function fromConfig(array $classes): self
    {
        return new self(array_map(static fn (string $class): BankDefinition => app($class), array_values($classes)));
    }

    /** @return array<string, BankDefinition> */
    public function all(): array
    {
        return $this->banks;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->banks);
    }

    public function get(string $id): BankDefinition
    {
        return $this->banks[$id] ?? throw new InvalidArgumentException(sprintf('Unknown bank "%s".', $id));
    }

    /**
     * The first parser (of $bankId, or of any bank) that supports the file.
     *
     * @return null|array{0: BankDefinition, 1: StatementParser}
     */
    public function parserFor(StatementFile $file, ?string $bankId = null): ?array
    {
        foreach (null === $bankId ? $this->banks : [$this->get($bankId)] as $bank) {
            foreach ($bank->statementParsers() as $parser) {
                if ($parser->supports($file)) {
                    return [$bank, $parser];
                }
            }
        }

        return null;
    }
}
