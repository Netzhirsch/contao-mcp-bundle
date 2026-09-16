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
 *
 * Two consequences of that exemption, both of which have bitten a provider author:
 *
 *   1. FIELD NAMES MUST BE UNIQUE TO YOUR BUNDLE. Skipping the palette check also
 *      means skipping the "is this a column of that table?" question. A declared
 *      field named like an existing column — `headline`, `text`, `url`, `cssID` on
 *      tl_content — is not rejected as a duplicate: the core mapper writes the
 *      column, then your provider runs last and writes it again. The core value is
 *      quietly replaced. Prefix your fields (`mybundle_headline`), and prefix them
 *      especially when the names come from editor input rather than from your code.
 *
 *   2. getAllowedFields() IS the type gate, and it is honoured — the mapper calls
 *      it wherever the table has a type concept (tl_page, tl_content) and refuses
 *      a field your provider does not allow for that type, before apply() is
 *      reached. Tables without a type (tl_theme, tl_layout) pass null and skip it.
 *      Re-checking inside apply() is still good practice, since your provider is
 *      the only place that can tell a field of type A from a field of type B when
 *      getDeclaredFields() is the union over all of them.
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
     * Consulted for every table that has a type concept; a field left out here is
     * refused with a message naming your extension and the type, and apply() is
     * not called for it. On a table without a type concept (tl_theme, tl_layout)
     * there is nothing to gate on, so the gate is skipped entirely and what you
     * return for a null type does not decide anything.
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
