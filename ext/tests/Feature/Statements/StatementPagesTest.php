<?php

/*
 * StatementPagesTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Statements;

use FireflyIII\Ext\Http\Controllers\StatementController;
use FireflyIII\Ext\Models\Statement;
use FireflyIII\Ext\Models\StatementLine;
use FireflyIII\Ext\Statements\PdfTextExtractor;
use FireflyIII\Ext\Statements\StatementException;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Upload -> review -> commit -> re-upload adds nothing, through the HTTP routes, with the committed
 * anonymised FNB layout. pdftotext is replaced by a stub (ext/tests/fixtures/bin/fake-pdftotext).
 *
 * @internal
 */
#[CoversClass(StatementController::class)]
#[CoversClass(PdfTextExtractor::class)]
final class StatementPagesTest extends FeatureTestCase
{
    use StatementTestHelpers;

    private const string LAYOUT = __DIR__.'/../../fixtures/fnb-statement/transaction-history.layout.txt';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('upload');
        config(['ext.statements.pdftotext' => __DIR__.'/../../fixtures/bin/fake-pdftotext']);
        $this->withoutVite();
    }

    public function testUploadReviewCommitAndReUpload(): void
    {
        $user    = $this->createUser();
        $this->actingAs($user);
        $account = $this->createAccount($user, 'FNB Cheque', 'asset', '62012345678', 'EUR', ['opening_balance' => '10019.49', 'opening_balance_date' => \Carbon\Carbon::parse('2025-05-20', config('app.timezone'))]);
        // two email-style transactions that the statement must match
        $this->emailTransaction($user, $account, '2025-07-09', '-74.77', 'PnP God Fehulo', '1234');
        $this->emailTransaction($user, $account, '2025-07-12', '105779.24', 'Warev Gojiyeb');

        $this->get(route('ext.statements.index'))->assertOk()->assertSee('Upload a statement');
        $this->get(route('ext.statements.create'))->assertOk()->assertSee('detect from the statement');

        $pdf      = "%PDF-1.4\n".file_get_contents(self::LAYOUT);
        $response = $this->post(route('ext.statements.store'), [
            'file'    => UploadedFile::fake()->createWithContent('history.pdf', $pdf),
            'options' => ['import_missing' => '1', 'reconcile' => '1', 'adjust' => '0', 'apply_rules' => '1', 'auto_commit' => '0'],
        ]);
        $statement = Statement::query()->firstOrFail();
        $response->assertRedirect(route('ext.statements.show', [$statement->id]));
        self::assertSame($account->id, $statement->account_id);
        self::assertSame(60, $statement->lines()->count());
        self::assertSame(2, $statement->lines()->where('status', StatementLine::MATCHED)->count());
        self::assertSame(58, $statement->lines()->where('status', StatementLine::MISSING)->count());
        // the file is kept encrypted on the upload disk
        self::assertSame($pdf, Crypt::decrypt(Storage::disk('upload')->get($statement->file_path)));

        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()->assertSee('Balance breakdown')->assertSee('#MONTHLY ACCOUNT FEE')->assertSee('12480.55');

        // commit, but leave one missing line out
        $skip     = $statement->lines()->where('status', StatementLine::MISSING)->orderBy('posting_index')->first();
        $create   = $statement->lines()->where('status', StatementLine::MISSING)->where('id', '!=', $skip->id)->pluck('id')->all();
        $before   = TransactionJournal::count();
        $this->post(route('ext.statements.commit', [$statement->id]), ['create' => $create])->assertRedirect(route('ext.statements.show', [$statement->id]));
        $statement->refresh();
        self::assertSame(Statement::STATUS_COMMITTED, $statement->status);
        self::assertSame($before + 57, TransactionJournal::count());
        // the line that was left out stays "missing" and explains the rest of the difference
        self::assertSame(bcadd((string) $skip->amount, '0', 2), $statement->summary['breakdown']['missing']);
        self::assertSame('0.00', $statement->summary['breakdown']['unexplained']);
        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()->assertSee('57 transactions created')->assertSee('View the created transactions');

        // re-upload: same file refused, re-processed adds nothing new except the skipped line still missing
        $this->post(route('ext.statements.store'), ['file' => UploadedFile::fake()->createWithContent('history.pdf', $pdf)])->assertSessionHasErrors('file');
        $this->post(route('ext.statements.store'), ['file' => UploadedFile::fake()->createWithContent('history.pdf', $pdf), 'reprocess' => '1']);
        $second   = Statement::query()->orderByDesc('id')->firstOrFail();
        self::assertNotSame($statement->id, $second->id);
        self::assertSame(1, $second->lines()->where('status', StatementLine::MISSING)->count());
        $this->post(route('ext.statements.commit', [$second->id]), ['create' => []]);
        self::assertSame($before + 57, TransactionJournal::count());

        // other users cannot see it
        $this->actingAs($this->createUser('other@example.com'));
        $this->get(route('ext.statements.show', [$statement->id]))->assertNotFound();
    }

    public function testUploadErrors(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createAccount($user, 'Unrelated', 'asset', '99999');

        // account number not found -> ask the user
        $this->post(route('ext.statements.store'), ['file' => UploadedFile::fake()->createWithContent('history.pdf', "%PDF-1.4\n".file_get_contents(self::LAYOUT))])
            ->assertSessionHasErrors('account_id');
        // not a statement
        $this->post(route('ext.statements.store'), ['file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\nhello\n")])->assertSessionHasErrors('file');
        // not a PDF at all
        $this->post(route('ext.statements.store'), ['file' => UploadedFile::fake()->createWithContent('x.txt', 'plain')])->assertSessionHasErrors('file');
        self::assertSame(0, Statement::query()->count());
    }

    public function testExtractorPassesThePasswordAndReportsErrors(): void
    {
        $path      = tempnam(sys_get_temp_dir(), 'ext-pdf-');
        file_put_contents($path, "%PDF-1.4\nline\n");
        $extractor = new PdfTextExtractor(__DIR__.'/../../fixtures/bin/fake-pdftotext');
        self::assertSame("line\n", $extractor->extract($path, 'secret'));

        try {
            $extractor->extract($path, 'bad-pass');
            self::fail('a wrong password must fail');
        } catch (StatementException $e) {
            self::assertStringContainsString('password', $e->getMessage());
            self::assertStringNotContainsString('bad-pass', $e->getMessage());
        } finally {
            unlink($path);
        }
    }
}
