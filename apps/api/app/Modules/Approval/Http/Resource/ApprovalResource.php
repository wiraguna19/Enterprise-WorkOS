<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Resource;

use App\Modules\Approval\Infrastructure\Eloquent\ApprovalDecisionModel;
use App\Modules\Approval\Infrastructure\Eloquent\ApprovalModel;
use App\Modules\Platform\Http\Resource\BaseResource;

/**
 * @property ApprovalModel $resource
 */
final class ApprovalResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $subject = $this->resource->getAttribute('subject');

        return [
            'id' => $this->resource->id,
            'type' => 'approval',
            'status' => $this->resource->status,
            'policy' => $this->resource->policy,
            'required_approvals' => $this->resource->required_approvals,

            // The quorum progress a reviewer needs to see: "1 of 3" is the
            // difference between "my vote decides this" and "someone else will".
            'approvals_so_far' => $this->whenLoaded(
                'decisions',
                fn () => $this->resource->approvalsSoFar(),
            ),

            'subject' => $subject === null ? null : [
                'type' => $this->resource->subject_type,
                'id' => $this->resource->subject_id,
                'reference' => $subject->reference ?? null,
                'title' => $subject->title ?? null,
                'state_category' => $subject->state_category ?? null,
                'priority' => $subject->priority ?? null,
            ],

            'requested_by' => $this->whenLoaded('requester', fn () => [
                'membership_id' => $this->resource->requested_by_membership_id,
                'name' => $this->resource->requester?->user?->name,
            ]),

            'submission_note' => $this->resource->submission_note,
            'submitted_at' => $this->resource->submitted_at?->toIso8601String(),
            'resolved_at' => $this->resource->resolved_at?->toIso8601String(),

            'reviewers' => $this->whenLoaded('approvers', fn () => $this->resource->approvers
                ->map(fn ($a) => [
                    'membership_id' => $a->membership_id,
                    'name' => $a->membership?->user?->name,
                ])->values()->all()),

            // Every decision, including superseded ones. A changed decision
            // must show BOTH records, or the trail pretends the first one
            // never happened (docs/02 §4.3).
            'decisions' => $this->whenLoaded('decisions', fn () => $this->resource->decisions
                ->map(fn (ApprovalDecisionModel $d) => [
                    'id' => $d->id,
                    'decision' => $d->decision,
                    'comment' => $d->comment,
                    'reviewer' => $d->reviewer?->user?->name,
                    'decided_at' => $d->decided_at?->toIso8601String(),
                ])->values()->all()),

            'permissions' => $this->permissions([
                'decide' => 'decide',
                'withdraw' => 'withdraw',
            ]),
        ];
    }
}
