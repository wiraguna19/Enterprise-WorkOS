<?php

declare(strict_types=1);

namespace App\Modules\Work\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Work grouped under a milestone of a project it is not in (ADR 0056).
 *
 * Creation checked only that the milestone belonged to the ORGANIZATION, and
 * an edit checked only that the id was a uuid — so an item in ENG could be
 * put under a FIN milestone, counted in FIN's milestone and invisible from it,
 * and an id that named nothing reached the foreign key as a 500. Nothing in
 * the interface could send either while milestones had no interface; the
 * picker that now exists is what made the rule worth writing down.
 */
final class MilestoneElsewhere extends DomainException
{
    public function errorCode(): string
    {
        return 'work_item.milestone_not_in_project';
    }

    public static function forItemWithoutProject(): self
    {
        return new self('A milestone belongs to a project. Put this work in the project first.');
    }

    public static function notInProject(): self
    {
        return new self("That milestone is not one of this project's.");
    }
}
