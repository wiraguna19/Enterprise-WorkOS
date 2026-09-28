<?php

declare(strict_types=1);

namespace App\Modules\Work\Application\Service;

use App\Modules\Governance\Application\Service\AuditLogger;
use App\Modules\Governance\Application\Service\CustomFieldValues;
use App\Modules\Work\Domain\Exception\NoWorkflowForType;
use App\Modules\Work\Domain\Exception\WorkItemTemplateRefused;
use App\Modules\Work\Infrastructure\Eloquent\WorkItemTemplateModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Writing, changing and deleting an organization's work item templates
 * (ADR 0047).
 *
 * A template PREFILLS the create form; it creates nothing. That one decision is
 * why this class is small: every rule about what a new work item must be —
 * required custom fields, who may create one, what a project allows — stays in
 * `POST /work-items`, where the person submitting the form meets it exactly as
 * if they had typed every field themselves. A second creation path would need a
 * second copy of all of it, and the copy would be the weaker door.
 *
 * What this class does own is the SHAPE of a prefill, because `fields` is jsonb
 * and nothing else can.
 */
final class WorkItemTemplates
{
    /**
     * What a template may fill in.
     *
     * The same vocabulary as the recurrence template, on purpose — `due_in_days`
     * included — so that the day a rule action or a recurrence is allowed to
     * name a template, the two shapes are already one.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'title',
        'description',
        'type',
        'priority',
        'estimate_hours',
        'due_in_days',
        'custom_fields',
    ];

    /**
     * Keys a client could reasonably send, refused on purpose, each with the
     * sentence that says why.
     *
     * Named rather than lumped in with "unknown" because each one is a real
     * work item field, and a person told only "not allowed" will assume a bug
     * and try again.
     *
     * @var array<string, string>
     */
    private const REFUSED = [
        'assignee_id' => 'A template outlives the people it would name. Who does the work is chosen on the form.',
        'reviewer_id' => 'A template outlives the people it would name. Who signs it off is chosen on the form.',
        'project_id' => 'The project comes from where the form was opened, not from the template.',
        'parent_id' => 'A parent is one particular item, and a template is used for many.',
        'milestone_id' => 'A milestone belongs to one project, and a template belongs to none.',
        'start_date' => 'An absolute date in a template is the same date forever. Use due_in_days.',
        'due_at' => 'An absolute date in a template is the same date forever. Use due_in_days.',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CustomFieldValues $customFields,
        private readonly WorkItemService $workItems,
    ) {}

    /**
     * Every template, alphabetically.
     *
     * By name and not by age: the picker is read by somebody looking for a
     * word, and a list in creation order makes them read all of it.
     *
     * @return Collection<int, WorkItemTemplateModel>
     */
    public function all(): Collection
    {
        return WorkItemTemplateModel::query()
            ->orderByRaw('lower(name)')
            ->get();
    }

    public function find(string $id): WorkItemTemplateModel
    {
        $template = WorkItemTemplateModel::query()->find($id);

        if (! $template instanceof WorkItemTemplateModel) {
            throw WorkItemTemplateRefused::unknown($id);
        }

        return $template;
    }

    /** @param array<string, mixed> $fields */
    public function create(string $name, ?string $purpose, array $fields): WorkItemTemplateModel
    {
        $template = new WorkItemTemplateModel;
        $template->id = (string) new UuidV7;
        $template->name = trim($name);
        $template->purpose = $this->blankToNull($purpose);
        $template->fields = $this->shaped($fields);

        $this->save($template);

        $this->audit->record('work_item_template.created', [
            'name' => $template->name,
            'fields' => array_keys($template->fields),
        ], targetType: 'work_item_template', targetId: $template->id);

        return $template;
    }

    /**
     * Change what was sent, and only that.
     *
     * `fields` is REPLACED, not merged. A prefill is one composed thing — the
     * editor shows all of it and sends all of it — and a merge could never
     * remove a key, so clearing the priority on a template would be a save that
     * appears to work and changes nothing.
     *
     * @param  array{name?: string, purpose?: string|null, fields?: array<string, mixed>}  $changes
     */
    public function update(WorkItemTemplateModel $template, array $changes): WorkItemTemplateModel
    {
        $before = ['name' => $template->name, 'fields' => $template->fields];

        if (array_key_exists('name', $changes)) {
            $template->name = trim($changes['name']);
        }

        if (array_key_exists('purpose', $changes)) {
            $template->purpose = $this->blankToNull($changes['purpose']);
        }

        if (array_key_exists('fields', $changes)) {
            $template->fields = $this->shaped($changes['fields']);
        }

        $this->save($template);

        $this->audit->record('work_item_template.updated', [
            'before' => $before,
            'after' => ['name' => $template->name, 'fields' => $template->fields],
        ], targetType: 'work_item_template', targetId: $template->id);

        return $template;
    }

    /**
     * Delete, not retire.
     *
     * Nothing refers to a template — no work item records which one it started
     * from (ADR 0047) — so a retired template would be a row kept for nobody.
     * What it held goes into the audit entry, so "what did the old Incident
     * template fill in" still has an answer after it is gone.
     */
    public function delete(WorkItemTemplateModel $template): void
    {
        $snapshot = ['name' => $template->name, 'fields' => $template->fields];
        $id = $template->id;

        $template->delete();

        $this->audit->record(
            'work_item_template.deleted',
            $snapshot,
            targetType: 'work_item_template',
            targetId: $id,
        );
    }

    /**
     * The prefill as it will be stored: allowed keys only, blanks dropped,
     * custom field answers checked against the fields that exist today.
     *
     * Every refused key is reported at once. Blank values are dropped rather
     * than stored: `""` is not "no value" to the validator the form submits to,
     * so a stored blank would reach `POST /work-items` as a 422 about a field
     * the person never touched.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function shaped(array $fields): array
    {
        $refused = [];

        foreach (array_keys($fields) as $key) {
            if (! in_array($key, self::FIELDS, strict: true)) {
                $refused[(string) $key] = self::REFUSED[$key] ?? 'Not a field a work item has.';
            }
        }

        if ($refused !== []) {
            throw WorkItemTemplateRefused::notTemplatable($refused);
        }

        $shaped = [];

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'custom_fields') {
                /** @var array<string, mixed> $answers */
                $answers = is_array($value) ? $value : [];
                $answers = $this->customFields->normalize('work_item', $answers);

                if ($answers !== []) {
                    $shaped['custom_fields'] = $answers;
                }

                continue;
            }

            $shaped[$key] = $value;
        }

        if ($shaped === []) {
            throw WorkItemTemplateRefused::empty();
        }

        // A type no workflow routes would prefill a form that cannot be
        // submitted. Refused here with the same sentence the create endpoint
        // gives, rather than stored and discovered by the person using it.
        if (isset($shaped['type'])
            && ! in_array($shaped['type'], $this->workItems->creatableTypes(), strict: true)
        ) {
            throw NoWorkflowForType::for((string) $shaped['type']);
        }

        return $shaped;
    }

    /**
     * The name is decided by the unique index, not by a read first — two saves
     * in the same second both pass a SELECT. The catch turns the index's answer
     * into a sentence.
     *
     * Inside its own transaction so a refused insert rolls back to a savepoint
     * when a caller already holds one open. In Postgres a failed statement
     * poisons the whole enclosing transaction, and every query after the 409
     * would fail with "current transaction is aborted" — an error that names
     * nothing to do with the name that was taken.
     */
    private function save(WorkItemTemplateModel $template): void
    {
        try {
            DB::transaction(fn (): bool => $template->save());
        } catch (QueryException $e) {
            // Postgres' unique-violation SQLSTATE, matched on the code because
            // the message is localised by the server.
            if ($e->getCode() === '23505') {
                throw WorkItemTemplateRefused::nameTaken($template->name);
            }

            throw $e;
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
