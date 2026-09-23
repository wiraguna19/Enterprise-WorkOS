<?php

declare(strict_types=1);

namespace App\Modules\Governance\Domain\Exception;

use App\Modules\Platform\Domain\Exception\DomainException;

/**
 * Every refusal the custom field machinery can express, each with a name.
 *
 * One class with named constructors rather than eight files, because these are
 * one decision seen from eight angles and a reader needs the whole set in front
 * of them. The `code` is what a client branches on (docs/05 §3), and each one
 * is specific enough that the screen can say something true without guessing —
 * "that key is taken" and "that is not one of the options" are different
 * sentences to the person typing.
 */
final class CustomFieldRefused extends DomainException
{
    /**
     * NOT `$code`.
     *
     * `\Exception` already has a `$code` property — readwrite, untyped,
     * protected — and a promoted `private readonly string $code` here silently
     * overrides it. PHPStan called all three halves of that out at once:
     * narrowing the visibility, adding a native type the parent does not have,
     * and making a readwrite property readonly. None of them would have thrown
     * until something asked the exception for its numeric code.
     *
     * The lesson is older than this class: a base class's fields are part of
     * the namespace you are writing in.
     *
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        string $message,
        private readonly string $refusalCode,
        private readonly int $status = 422,
        array $details = [],
    ) {
        parent::__construct($message, $details);
    }

    public function errorCode(): string
    {
        return $this->refusalCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function keyTaken(string $key): self
    {
        return new self(
            "A field with the key \"{$key}\" already exists for this scope.",
            'custom_field.key_taken',
            409,
            ['key' => $key],
        );
    }

    /**
     * The key is frozen once the field exists.
     *
     * `filter[cf_<key>]` is published API grammar (docs/05 §4), so a key is not
     * a label that happens to be lower-case — it is the name every saved link
     * and every integration uses. The label is the part that may change.
     */
    public static function keyIsFrozen(): self
    {
        return new self(
            'A field\'s key cannot be changed. Its label can.',
            'custom_field.key_is_frozen',
        );
    }

    /** The type decides which column the answer lands in, so it cannot move. */
    public static function typeIsFrozen(): self
    {
        return new self(
            'A field\'s type cannot be changed once it exists. Retire it and declare a new one.',
            'custom_field.type_is_frozen',
        );
    }

    public static function notLive(string $label): self
    {
        return new self(
            "\"{$label}\" has been retired and no longer accepts answers.",
            'custom_field.retired',
            422,
            ['label' => $label],
        );
    }

    public static function required(string $label): self
    {
        return new self(
            "\"{$label}\" is required.",
            'custom_field.required',
            422,
            ['label' => $label],
        );
    }

    /** @param list<string> $options */
    public static function notAnOption(string $label, array $options): self
    {
        return new self(
            "\"{$label}\" does not offer that option.",
            'custom_field.not_an_option',
            422,
            ['label' => $label, 'options' => $options],
        );
    }

    public static function wrongType(string $label, string $type): self
    {
        return new self(
            "\"{$label}\" expects a {$type}.",
            'custom_field.wrong_type',
            422,
            ['label' => $label, 'type' => $type],
        );
    }

    /**
     * A select with no options is a field nobody can answer.
     *
     * Refused at declaration rather than discovered at the form: a picker with
     * an empty list is the dead control this product keeps paying for, and it
     * looks like a loading bug to whoever meets it.
     */
    public static function selectNeedsOptions(): self
    {
        return new self(
            'A select field needs at least one option.',
            'custom_field.select_needs_options',
        );
    }

    public static function unknownField(string $id): self
    {
        return new self(
            'That field does not exist for this organization.',
            'custom_field.unknown',
            404,
            ['id' => $id],
        );
    }
}
