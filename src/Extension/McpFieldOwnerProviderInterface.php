<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Extension;

/**
 * Optional opt-in: name the tool that owns a column your bundle attaches to a
 * core table, so the core's refusals can point at it.
 *
 * Why this exists. A bundle that hangs a field on `tl_page` gets it managed by
 * its own tools, and the generic write path refuses it on purpose — a foreign
 * key written as free text is a dangling reference, not an unusual value. But
 * "you may not write this here" is only half an answer, and the missing half
 * has now cost two live-site detours: both times the refusal was read as "there
 * is no way to do this at all", and both times there was a tool that did it.
 *
 * The core cannot guess the name of a tool in your bundle, and hardcoding one
 * customer's field into a product every other customer installs is the kind of
 * maintained list this bundle keeps removing. So you declare it:
 *
 *     public function getMcpFieldOwners(): array
 *     {
 *         return [
 *             'tl_page.netzhirschPageState' => [
 *                 'write' => 'pagestate_assign(page_id: <id>, state_id: <id>)',
 *                 'read'  => 'pagestate_of_page(page_id: <id>)',
 *             ],
 *         ];
 *     }
 *
 * The values are written out as CALLS, not bare tool names, because the caller
 * reading the message is about to make one. Give the argument names; a
 * placeholder like `<id>` is fine and reads as intended.
 *
 * Both keys are optional — declare `write` alone if the field has no dedicated
 * read path yet. A `read` hint is used where a value could not be returned, a
 * `write` hint where one was refused.
 *
 * This is presentation only: declaring an owner does NOT grant, widen or
 * narrow any permission, and it does not make the field writable through the
 * generic path. It only decides what the error message says.
 *
 * @see McpToolPermissionProviderInterface for the permission counterpart
 */
interface McpFieldOwnerProviderInterface extends McpToolProviderInterface
{
    /**
     * Map of `"tl_table.field"` → `['write' => string, 'read' => string]`.
     *
     * Keys that are not of the form `tl_<table>.<field>` are ignored, as are
     * non-string hints. First declaration wins on a collision, and a core
     * mapping always beats an extension's.
     *
     * Keep this method pure and cheap: it is called to build the map and must
     * not perform I/O or rely on request state.
     *
     * @return array<string, array<string, string>>
     */
    public function getMcpFieldOwners(): array;
}
