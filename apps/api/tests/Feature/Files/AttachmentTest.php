<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Attaching a file, and — the half that did not exist — reading back what is
 * attached.
 *
 * `POST /work-items/{reference}/attachments` has written attachment rows since
 * Phase 2 with nothing listing them: a write path with no read path, which is
 * the shape the activity log rotted in for three phases (docs/11 §7). A file
 * picker built on top of that would have uploaded into silence.
 */
beforeEach(function (): void {
    $this->employee = $this->loginAs('sarah@acme.test');
});

/** A file row in the state a completed, scanned upload leaves behind. */
function availableFile(string $name = 'runbook.pdf'): string
{
    $id = (string) new UuidV7;

    DB::table('files')->insert([
        'id' => $id,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'path' => 'org/test/'.$id,
        'original_name' => $name,
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'upload_state' => 'complete',
        'scan_status' => 'clean',
    ]);

    return $id;
}

it('lists what is attached to a work item', function (): void {
    $file = availableFile('rollback-plan.pdf');

    $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/attachments', ['file_id' => $file])
        ->assertCreated();

    $rows = $this->withToken($this->employee)
        ->getJson('/api/v1/work-items/ENG-144/attachments')
        ->assertOk()
        ->json('data');

    $attached = collect($rows)->firstWhere('file.id', $file);

    expect($attached)->not->toBeNull()
        ->and($attached['file']['name'])->toBe('rollback-plan.pdf')
        ->and($attached['file']['available'])->toBeTrue()
        // The name of the person who put it there, not the id: a list that
        // renders a uuid is a list nobody reads.
        ->and($attached['attached_by'])->not->toBeNull();
});

it('says a file is not available yet rather than hiding it', function (): void {
    $id = (string) new UuidV7;

    DB::table('files')->insert([
        'id' => $id,
        'organization_id' => '01900000-0000-7000-8000-0000000000ac',
        'path' => 'org/test/'.$id,
        'original_name' => 'scanning.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'upload_state' => 'complete',
        'scan_status' => 'pending',
    ]);

    $this->withToken($this->employee)
        ->postJson('/api/v1/work-items/ENG-144/attachments', ['file_id' => $id])
        ->assertCreated();

    $row = collect(
        $this->withToken($this->employee)
            ->getJson('/api/v1/work-items/ENG-144/attachments')
            ->assertOk()
            ->json('data')
    )->firstWhere('file.id', $id);

    // Present, and honest about its state. Filtering it out would read as a
    // failed upload to the person who just made it — the one thing it is not.
    expect($row)->not->toBeNull()
        ->and($row['file']['available'])->toBeFalse()
        ->and($row['file']['scan_status'])->toBe('pending');
});

it('refuses to list attachments on work the caller cannot see', function (): void {
    // Not 403: a 403 confirms the item exists, and confirming existence across
    // a visibility boundary is the leak (docs/05 §3).
    $this->withToken($this->employee)
        ->getJson('/api/v1/work-items/GLX-1/attachments')
        ->assertNotFound();
});

it('refuses a file type the product does not accept', function (): void {
    $this->withToken($this->employee)
        ->postJson('/api/v1/files/upload-url', [
            'name' => 'payload.exe',
            'mime_type' => 'application/x-msdownload',
            'size_bytes' => 1024,
        ])
        ->assertStatus(422);
});
