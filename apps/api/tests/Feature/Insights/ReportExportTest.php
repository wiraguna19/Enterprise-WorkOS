<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\UuidV7;

/**
 * Report exports, asserted against ADR 0011.
 *
 * The assertion that matters is the first one: a queued job builds a file with
 * nobody watching, and if it runs as the system rather than as the requester,
 * the file is a data leak that looks completely correct in review. Everything
 * else here is lifecycle.
 *
 * The queue is `sync` in this suite (see phpunit.xml.dist), so the job runs
 * inside the request and the row is ready by the time the response returns.
 */
const E_ORG = '01900000-0000-7000-8000-0000000000ac';
const E_WF = '01900001-0000-7000-8000-000000000001';
const E_S_DONE = '01900002-0000-7000-8000-000000000006';
const E_FIN = '01900003-0000-7000-8000-000000000005';  // private; Rina owns it
const E_ENG = '01900003-0000-7000-8000-000000000001';  // internal; Ahmad owns it
const E_RINA = '01900000-0000-7000-8000-000000000201';
const E_MANAGER_ROLE = '01900000-0000-7000-8000-000000000402';

beforeEach(function (): void {
    // The bucket is a temp dir for the duration of the test.
    Storage::fake(config('filesystems.default'));
});

/** Export is an org-admin permission by default; Ahmad needs it to be compared. */
function grantExportToManagers(): void
{
    $permissionId = DB::table('permissions')->where('key', 'report.export')->value('id');

    DB::table('role_permissions')->insertOrIgnore([
        'role_id' => E_MANAGER_ROLE,
        'permission_id' => $permissionId,
    ]);
}

function completedItem(?string $projectId, string $reference, string $completedAt): string
{
    $id = (string) new UuidV7;

    DB::table('work_items')->insert([
        'id' => $id,
        'organization_id' => E_ORG,
        'reference' => $reference,
        'title' => 'Export fixture '.$reference,
        'project_id' => $projectId,
        'workflow_id' => E_WF,
        'workflow_state_id' => E_S_DONE,
        'state_category' => 'done',
        'completed_at' => $completedAt,
        // Created by Rina, so nothing reaches Ahmad through the "work they
        // created" clause and the only route to it is the project.
        'created_by_membership_id' => E_RINA,
    ]);

    DB::table('work_item_transitions')->insert([
        'id' => (string) new UuidV7,
        'organization_id' => E_ORG,
        'work_item_id' => $id,
        'to_state_id' => E_S_DONE,
        'to_category' => 'done',
        'occurred_at' => $completedAt,
    ]);

    return $id;
}

/** @return array<string, mixed> the export row as the API presents it */
function exportReport(string $token, string $key, string $query = ''): array
{
    return test()->withToken($token)
        ->postJson("/api/v1/reports/{$key}/export?{$query}")
        ->assertStatus(202)
        ->json('data');
}

function exportContents(string $exportId): string
{
    $path = DB::table('report_exports')->where('id', $exportId)->value('storage_path');

    // Cast: the disk returns null for a missing object, and a test that
    // silently compared against null would pass for the wrong reason.
    return (string) Storage::disk(config('filesystems.default'))->get((string) $path);
}

it('builds the file with the requester\'s eyes, never the worker\'s', function (): void {
    grantExportToManagers();

    completedItem(E_ENG, 'EXP-1', '2026-03-05 09:00:00');
    // In the private Finance project, which Rina owns and Ahmad is not in.
    completedItem(E_FIN, 'EXP-2', '2026-03-06 09:00:00');

    $window = 'from=2026-03-01&to=2026-03-31';

    $rina = exportReport($this->loginAs('rina@acme.test'), 'organization', $window);
    $ahmad = exportReport($this->loginAs('ahmad@acme.test'), 'organization', $window);

    expect($rina['status'])->toBe('ready')
        ->and($ahmad['status'])->toBe('ready');

    // The whole ADR in two assertions: the same report, the same window, two
    // different files, because the worker bound the requester's membership.
    expect(exportContents($rina['id']))->toContain('EXP-1')->toContain('EXP-2')
        ->and(exportContents($ahmad['id']))->toContain('EXP-1')->not->toContain('EXP-2');

    // And the difference is stated rather than left as a shortfall nobody can
    // explain: the count Ahmad could not see travels on his row.
    expect($rina['hidden_count'])->toBe(0)
        ->and($ahmad['hidden_count'])->toBe(1);
});

it('writes a byte-order mark so the file opens correctly elsewhere', function (): void {
    completedItem(E_ENG, 'EXP-3', '2026-03-05 09:00:00');

    $export = exportReport($this->loginAs('rina@acme.test'), 'organization', 'from=2026-03-01&to=2026-03-31');

    // Three bytes that decide whether every non-ASCII name in this product
    // arrives as itself or as mojibake.
    expect(exportContents($export['id']))->toStartWith("\u{FEFF}")
        ->and(exportContents($export['id']))->toContain('reference,title');
});

/**
 * The refusal outlives the thing it refused.
 *
 * `xlsx` was the example the 422 was written for and is now a real writer, so
 * the assertion moves to a format that still has none. The rule ADR 0011 states
 * is about mislabelling, not about a particular extension: an `.ods` answered
 * with a CSV would open, look right, and lie in exactly the same way.
 */
it('refuses a format nothing can write rather than mislabelling a CSV', function (): void {
    $this->withToken($this->loginAs('rina@acme.test'))
        ->postJson('/api/v1/reports/organization/export?format=ods')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'report.format_unsupported');
});

/**
 * A real .xlsx, asserted by its bytes.
 *
 * An xlsx is a ZIP whose first entry is `[Content_Types].xml`. Checking the two
 * magic bytes and that entry is the difference between "a file was produced"
 * and "a spreadsheet was produced" — and a CSV renamed to `.xlsx` passes the
 * first check and fails this one, which is the whole point of the format having
 * been refused rather than faked.
 */
it('writes a real xlsx when one is asked for', function (): void {
    completedItem(E_ENG, 'XLS-1', '2026-03-05 09:00:00');

    $export = exportReport(
        $this->loginAs('rina@acme.test'),
        'organization',
        'from=2026-03-01&to=2026-03-31&format=xlsx',
    );

    $contents = exportContents($export['id']);

    expect($contents)->toStartWith('PK')
        ->and($contents)->toContain('[Content_Types].xml')
        // No BOM, and no comma-separated header: those belong to the other
        // writer, and a downgrade would show up here first.
        ->and($contents)->not->toStartWith("\u{FEFF}");

    $row = DB::table('report_exports')->where('id', $export['id'])->first();

    // The three facts that have to agree, and the reason a writer owns all
    // three: a name, a stored object and a served type that disagree are how a
    // file ends up being something other than what it says it is.
    expect($row->format)->toBe('xlsx')
        ->and($row->filename)->toEndWith('.xlsx')
        ->and($row->storage_path)->toEndWith('.xlsx');
});

it('records what was asked for, so a file can be explained later', function (): void {
    $export = exportReport($this->loginAs('rina@acme.test'), 'organization', 'from=2026-03-01&to=2026-03-31');

    expect($export['parameters'])->toMatchArray([
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ]);
});

it('does not hand one person another person\'s export', function (): void {
    grantExportToManagers();

    $rina = exportReport($this->loginAs('rina@acme.test'), 'organization');

    // Not 403: an export built with someone else's visibility is not a thing
    // this reader gets to know exists.
    $this->withToken($this->loginAs('ahmad@acme.test'))
        ->getJson("/api/v1/reports/exports/{$rina['id']}/download")
        ->assertNotFound();
});

it('deletes the file when the export expires, and says so on the row', function (): void {
    $export = exportReport($this->loginAs('rina@acme.test'), 'organization');

    $path = (string) DB::table('report_exports')->where('id', $export['id'])->value('storage_path');

    DB::table('report_exports')
        ->where('id', $export['id'])
        ->update(['expires_at' => now()->subDay()]);

    $this->artisan('insights:prune-expired-exports')->assertSuccessful();

    $row = DB::table('report_exports')->where('id', $export['id'])->first();

    // The object goes; the row stays. "Where did my file go" is asked after the
    // fact, and the row is the only thing that can answer it.
    expect($row->status)->toBe('expired')
        ->and($row->storage_path)->toBeNull()
        ->and(Storage::disk(config('filesystems.default'))->exists($path))->toBeFalse();
});

it('will not download an export whose file is gone', function (): void {
    $token = $this->loginAs('rina@acme.test');
    $export = exportReport($token, 'organization');

    DB::table('report_exports')
        ->where('id', $export['id'])
        ->update(['expires_at' => now()->subDay()]);

    $this->artisan('insights:prune-expired-exports')->assertSuccessful();

    $this->withToken($token)
        ->getJson("/api/v1/reports/exports/{$export['id']}/download")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'report.export_not_ready');
});

it('is not available to someone without the export permission', function (): void {
    // Employees hold neither report.view nor report.export. A full extract of
    // delivery data is an organization-level act (docs/06 §2).
    $this->withToken($this->loginAs('sarah@acme.test'))
        ->postJson('/api/v1/reports/organization/export')
        ->assertForbidden();
});

it('has no report it does not define', function (): void {
    $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/reports/vibes')
        ->assertNotFound();
});

it('reports one person\'s own work without a way to ask about anybody else', function (): void {
    $token = $this->loginAs('rina@acme.test');

    // The personal report takes no membership parameter, and passing one
    // changes nothing: docs/02 §11 rules out per-person delivery reporting, and
    // this is the endpoint that would quietly become it.
    $mine = $this->withToken($token)
        ->getJson('/api/v1/reports/personal')
        ->assertOk()
        ->json('data');

    $spoofed = $this->withToken($token)
        ->getJson('/api/v1/reports/personal?membership=01900000-0000-7000-8000-000000000203')
        ->assertOk()
        ->json('data');

    expect($spoofed)->toBe($mine);
});

/**
 * The list says what can be asked for.
 *
 * Without this the screen offering an export keeps its own copy of the format
 * list — the same two-lists problem the registry solved on the server, moved
 * one layer out. A client offering a format before its writer exists produces a
 * 422 the reader did nothing to deserve; a client still offering only `csv`
 * hides a format that shipped.
 */
it('tells the client which formats it may ask for', function (): void {
    $formats = $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/reports/exports')
        ->assertOk()
        ->json('meta.formats');

    expect($formats)->toBe(['csv', 'xlsx']);
});

/**
 * The catalogue: every report, and what it cannot be built without.
 *
 * The registry has held four reports since exports shipped and only one screen
 * could reach any of them. A screen that wants to offer the other three needs
 * to know that `project` takes a project and `personal` takes nothing — and the
 * only alternative to asking is keeping a copy, which is the two-lists problem
 * that has already produced a request accepted for a file nothing could write.
 */
it('lists every report with its columns and what it requires', function (): void {
    $catalogue = $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/reports/catalogue')
        ->assertOk()
        ->json('data');

    expect(collect($catalogue)->pluck('key')->all())
        ->toBe(['project', 'team', 'personal', 'organization']);

    $byKey = collect($catalogue)->keyBy('key');

    expect($byKey['project']['requires'])->toBe(['project'])
        ->and($byKey['team']['requires'])->toBe(['team'])
        // Not "no parameters" — no REQUIRED ones. Every report takes a window,
        // and every report defaults it, so a window is an option.
        ->and($byKey['personal']['requires'])->toBe([])
        ->and($byKey['organization']['requires'])->toBe([])
        ->and($byKey['organization']['columns'])->toContain('cycle_time_hours');
});

/**
 * `catalogue` is not a report.
 *
 * It sits on the same path segment as `reports/{key}`, and a wildcard declared
 * first would answer 404 for it — the kind of routing accident that looks like
 * a missing feature.
 */
it('does not let the report wildcard swallow the catalogue', function (): void {
    $this->withToken($this->loginAs('rina@acme.test'))
        ->getJson('/api/v1/reports/catalogue')
        ->assertOk();
});
