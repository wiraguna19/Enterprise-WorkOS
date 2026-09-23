<?php

declare(strict_types=1);

namespace App\Modules\Governance\Application\Service;

use App\Modules\Governance\Domain\Exception\CustomFieldRefused;
use App\Modules\Governance\Infrastructure\Eloquent\CustomFieldDefinitionModel;
use App\Modules\Governance\Infrastructure\Eloquent\CustomFieldValueModel;
use App\Modules\Platform\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * What a record answered to the fields its organization declared (ADR 0038).
 *
 * This service is the only thing that writes `custom_field_values`, and the
 * only place that knows which typed column a type answers into — the map lives
 * on the definition model, which is the thing that owns the type.
 *
 * Governance may depend on nothing but Platform (deptrac), so a subject is a
 * scope and a uuid here. Work and Organization call in; nothing calls out.
 */
final class CustomFieldValues
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CustomFields $fields,
    ) {}

    /**
     * Every field that applies to a subject, with its answer if there is one.
     *
     * Shaped here, not in the caller's resource. Work and Organization both
     * render this list, and a payload assembled twice is two payloads that
     * eventually disagree — the shape this codebase has already paid for with
     * `reviewers` and `approvers`.
     *
     * RETIRED fields appear when the subject answered them, and not otherwise.
     * That is the whole reason retiring is not deleting: the form stops asking,
     * the record keeps saying. A screen rendering this list does not have to
     * know which is which — it renders what it is given, in order.
     *
     * One query for the definitions and one for the answers, joined in PHP. A
     * LEFT JOIN would return each definition once per subject, which is the
     * same data through a wider pipe.
     *
     * @return list<array<string, mixed>>
     */
    public function forSubject(string $scope, string $subjectId): array
    {
        $definitions = $this->fields->all($scope);

        $answers = CustomFieldValueModel::query()
            ->where($this->subjectColumn($scope), $subjectId)
            ->get()
            ->keyBy('definition_id');

        $out = [];

        foreach ($definitions as $definition) {
            /** @var CustomFieldValueModel|null $answer */
            $answer = $answers->get($definition->id);

            if (! $definition->isLive() && $answer === null) {
                continue;
            }

            $out[] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'type' => $definition->type,
                'options' => $definition->options(),
                'required' => $definition->required,
                // The form needs to know not to offer a control for this one,
                // while still printing what it holds.
                'live' => $definition->isLive(),
                'value' => $answer === null ? null : $this->read($definition, $answer),
            ];
        }

        return $out;
    }

    /**
     * The same list for a record that does not exist yet.
     *
     * A blank form, in the shape the detail payload uses, so the component that
     * renders one renders the other. Live fields only: nothing can answer a
     * retired field on a new record, and offering one would be a control whose
     * write the API refuses.
     *
     * A separate method rather than `forSubject($scope, <made-up id>)`, because
     * a query for the answers of an id that cannot exist is a query asking a
     * question with a known answer — and the next reader would have to work out
     * that the id was fake.
     *
     * @return list<array<string, mixed>>
     */
    public function blankFor(string $scope): array
    {
        $out = [];

        foreach ($this->fields->live($scope) as $definition) {
            $out[] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'type' => $definition->type,
                'options' => $definition->options(),
                'required' => $definition->required,
                'live' => true,
                'value' => null,
            ];
        }

        return $out;
    }

    /**
     * Write the answers a client sent, keyed by field KEY.
     *
     * Keyed by key rather than by id because that is what the API grammar
     * already says out loud (`cf_client`), and a client that has to look an id
     * up to send a value will eventually send a stale one.
     *
     * **A key the organization has not declared is a 422, never a silent
     * skip.** docs/05 §4 makes the same promise for filters, for the same
     * reason: silently ignoring an unknown key is how a client ships a broken
     * field nobody notices for a month.
     *
     * Null (or an empty string) removes the answer. That is the ONLY way a
     * value row disappears, and it is why a row never holds all-nulls: absent
     * and blank have to be the same state, or "required" cannot be checked.
     *
     * **Clearing a REQUIRED field is refused; not having answered one is not.**
     * The two halves of that sentence are the whole of how `required` behaves
     * here (ADR 0038). Demanding an answer on every edit would make a field
     * declared today retroactively block every item created before it — and a
     * person fixing a typo would be refused over a field they have never seen.
     * Refusing only the removal keeps the guarantee that matters: nothing takes
     * an answer away once it exists.
     *
     * @param  array<string, mixed>  $answers
     */
    public function write(string $scope, string $subjectId, array $answers): void
    {
        if ($answers === []) {
            return;
        }

        $definitions = $this->fields->all($scope)->keyBy('key');

        DB::transaction(function () use ($scope, $subjectId, $answers, $definitions): void {
            foreach ($answers as $key => $raw) {
                $definition = $definitions->get($key);

                if (! $definition instanceof CustomFieldDefinitionModel) {
                    throw CustomFieldRefused::unknownField((string) $key);
                }

                if ($raw === null || $raw === '') {
                    if ($definition->required) {
                        throw CustomFieldRefused::required($definition->label);
                    }

                    $this->forget($definition, $scope, $subjectId);

                    continue;
                }

                if (! $definition->isLive()) {
                    // Refused rather than accepted quietly: a form that can
                    // still write a retired field is a form showing a control
                    // the administrator thought they had removed.
                    throw CustomFieldRefused::notLive($definition->label);
                }

                $this->put($definition, $scope, $subjectId, $this->coerce($definition, $raw));
            }
        });
    }

    /**
     * Are the required fields answered?
     *
     * Asked separately from {@see write()}, and asked only at CREATION. A new
     * record is the one moment where "every required field is answered" can be
     * demanded without punishing somebody for a field that did not exist when
     * their item was made. After that, {@see write()} defends the answers that
     * exist rather than demanding ones that do not.
     *
     * @return list<string> the labels of the fields still unanswered
     */
    public function missingRequired(string $scope, string $subjectId): array
    {
        $answered = CustomFieldValueModel::query()
            ->where($this->subjectColumn($scope), $subjectId)
            ->pluck('definition_id')
            ->all();

        $missing = [];

        foreach ($this->fields->live($scope) as $definition) {
            if ($definition->required && ! in_array($definition->id, $answered, strict: true)) {
                $missing[] = $definition->label;
            }
        }

        return $missing;
    }

    /**
     * The value as the API should send it.
     *
     * A number leaves as a STRING, not a float. The column is numeric(18,4)
     * precisely so a quantity or an amount survives, and casting it to a
     * double on the way out would undo that in the last hop — the place this
     * kind of loss is hardest to notice, because everything upstream is right.
     */
    private function read(CustomFieldDefinitionModel $definition, CustomFieldValueModel $value): string
    {
        return match ($definition->type) {
            'number' => (string) $value->value_number,
            'date' => $value->value_date?->toDateString() ?? '',
            default => (string) $value->value_text,
        };
    }

    /**
     * The client's input as the column will take it.
     *
     * Refusals are by field LABEL, because that is the word on the screen. A
     * message naming `cf_delivery_date` tells the person typing nothing they
     * can act on.
     */
    private function coerce(CustomFieldDefinitionModel $definition, mixed $raw): string
    {
        $input = trim((string) (is_scalar($raw) ? $raw : ''));

        return match ($definition->type) {
            'number' => $this->coerceNumber($definition, $input),
            'date' => $this->coerceDate($definition, $input),
            'select' => $this->coerceOption($definition, $input),
            default => $this->coerceText($definition, $input),
        };
    }

    private function coerceText(CustomFieldDefinitionModel $definition, string $input): string
    {
        // Long enough for a sentence, short enough that a field is not an
        // essay. A text custom field that accepts a document is how a table
        // row stops rendering.
        if (mb_strlen($input) > 500) {
            throw CustomFieldRefused::wrongType($definition->label, 'shorter answer');
        }

        return $input;
    }

    private function coerceNumber(CustomFieldDefinitionModel $definition, string $input): string
    {
        if (! is_numeric($input)) {
            throw CustomFieldRefused::wrongType($definition->label, 'number');
        }

        // Handed on as the string the person typed, not as a float: the column
        // is numeric and Postgres parses it exactly. Round-tripping through a
        // PHP float first is the one step that could lose a digit.
        return $input;
    }

    private function coerceDate(CustomFieldDefinitionModel $definition, string $input): string
    {
        $date = date_create_immutable($input);

        if ($date === false) {
            throw CustomFieldRefused::wrongType($definition->label, 'date');
        }

        return $date->format('Y-m-d');
    }

    private function coerceOption(CustomFieldDefinitionModel $definition, string $input): string
    {
        $options = $definition->options();

        if (! in_array($input, $options, strict: true)) {
            throw CustomFieldRefused::notAnOption($definition->label, $options);
        }

        return $input;
    }

    /**
     * Insert or replace the one answer this subject has for this field.
     *
     * An upsert rather than a read-then-write: two saves of the same form in
     * the same second would both find nothing, both insert, and the second
     * would come back a 500 instead of the save the person asked for.
     *
     * Raw SQL rather than the query builder's `upsert()`, and the reason is the
     * index. The uniqueness is enforced by a PARTIAL index — one per subject
     * type, `WHERE work_item_id IS NOT NULL` — because a nullable column in a
     * plain unique index does not constrain the rows where it is null. Postgres
     * will only infer a partial index for ON CONFLICT when the statement
     * repeats its predicate, and `upsert()` has nowhere to put one: it emits
     * `ON CONFLICT (cols)` and the database answers "no unique or exclusion
     * constraint matching the ON CONFLICT specification" — at runtime, on a
     * path no unit test of the service would reach without a database.
     *
     * The column names are interpolated, which is only safe because both come
     * from closed maps in this file and on the definition model. Nothing a
     * client sends reaches this string.
     */
    private function put(
        CustomFieldDefinitionModel $definition,
        string $scope,
        string $subjectId,
        string $value,
    ): void {
        $column = $definition->valueColumn();
        $subject = $this->subjectColumn($scope);

        DB::statement(
            <<<SQL
                INSERT INTO custom_field_values
                    (id, organization_id, definition_id, {$subject}, {$column}, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, now(), now())
                ON CONFLICT (organization_id, definition_id, {$subject})
                    WHERE {$subject} IS NOT NULL
                DO UPDATE SET {$column} = EXCLUDED.{$column}, updated_at = now()
            SQL,
            [
                (string) new UuidV7,
                $this->tenant->organizationId(),
                $definition->id,
                $subjectId,
                $value,
            ],
        );
    }

    private function forget(CustomFieldDefinitionModel $definition, string $scope, string $subjectId): void
    {
        CustomFieldValueModel::query()
            ->where('definition_id', $definition->id)
            ->where($this->subjectColumn($scope), $subjectId)
            ->delete();
    }

    /**
     * Which subject column a scope answers into.
     *
     * A match, not a string built from the scope: `$scope.'_id'` would happily
     * produce a column name that does not exist and fail at the database with a
     * message nobody can trace back to here.
     */
    private function subjectColumn(string $scope): string
    {
        return match ($scope) {
            'work_item' => 'work_item_id',
            'project' => 'project_id',
            default => throw CustomFieldRefused::unknownField($scope),
        };
    }
}
