<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Trying a rule against one work item (ADR 0035).
 *
 * The subject is named by REFERENCE rather than by id, because this form is
 * filled in by a person who has "ENG-142" in front of them and no way to know
 * the uuid behind it. The lookup is case-insensitive for the same reason.
 *
 * `apply` defaults to false. A route whose default behaviour changes live data
 * is a route somebody triggers by exploring, and the whole value of this one is
 * that an administrator can find out what a rule does BEFORE it does it.
 */
final class RunRuleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:40'],
            'apply' => ['sometimes', 'boolean'],
        ];
    }

    public function reference(): string
    {
        return $this->string('reference')->toString();
    }

    public function shouldApply(): bool
    {
        return $this->boolean('apply');
    }
}
