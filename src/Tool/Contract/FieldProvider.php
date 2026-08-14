<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Contract;

use Contao\Model;

/**
 * Plugin point for third-party Contao bundles that decorate an existing entity with
 * additional database fields (e.g. terminal42/contao-changelanguage adds languageMain
 * / languageRoot / languageQuery to tl_page).
 *
 * A provider is *always* registered as a Symfony service via the
 * `netzhirsch.field_provider` tag; whether it actually contributes anything is decided
 * at runtime by isAvailable(). That way the FieldMapper can:
 *   - allow recognised fields when the extension IS installed → apply transparently
 *   - reject recognised fields when the extension is NOT installed → emit a clear
 *     "extension_not_available" error mentioning getRequiredExtension(), instead of
 *     a misleading "field not valid for this page type" message.
 *
 * Fields declared by a provider are *not* restricted by the entity's own type palette
 * (the provider takes responsibility for any per-type filtering inside getAllowedFields).
 */
interface FieldProvider
{
    /**
     * The Contao DB table this provider extends — e.g. 'tl_page', 'tl_news'.
     */
    public function getTable(): string;

    /**
     * The Composer package or class that must exist for the provider to function.
     * Used purely for human-readable error messages.
     */
    public function getRequiredExtension(): string;

    /**
     * Whether the provider's host extension is currently installed.
     * Typically a class_exists() check on a representative class.
     */
    public function isAvailable(): bool;

    /**
     * Every field name this provider claims, regardless of availability. Used to
     * detect "you sent an extension field but the extension isn't here" mismatches.
     *
     * @return list<string>
     */
    public function getDeclaredFields(): array;

    /**
     * Fields that should be accepted on create/update for the given resolved type.
     * Return [] when the provider's fields aren't valid for that type (e.g.
     * languageMain on a root page).
     *
     * Implementations MAY return non-empty results even when isAvailable() is false —
     * the caller is expected to gate on isAvailable() before actually applying.
     *
     * @return list<string>
     */
    public function getAllowedFields(?string $type): array;

    /**
     * Extra key/value pairs to merge into the read output (Serializer summary).
     * Should return an empty array when not available.
     *
     * @return array<string, mixed>
     */
    public function serialize(Model $model): array;

    /**
     * Apply the provider's recognised fields from $input to $model.
     * Returns the list of column names that were actually modified
     * (same diff semantics as the core FieldMapper).
     *
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    public function apply(Model $model, array $input, bool $detectChanges): array;
}
