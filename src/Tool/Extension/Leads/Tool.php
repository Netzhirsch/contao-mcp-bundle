<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Tool\Extension\Leads;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PhpMcp\Server\Attributes\McpTool;
use Terminal42\LeadsBundle\Terminal42LeadsBundle;

/**
 * Read-only MCP facade for terminal42/contao-leads (tl_lead + tl_lead_data).
 *
 * Leads ARE form submissions: the host extension captures them from the
 * frontend and the Contao backend renders them read-only (tl_lead is
 * `notEditable` + `closed`). We mirror that contract here — only list + get,
 * deliberately no create/update/delete. There is no sensible "write a lead"
 * operation; a submission originates from a real form post, not from an agent.
 *
 * The host extension is optional. Both tools first assert it is installed via
 * {@see ensureAvailable()} so the LLM gets a clean `extension_not_available`
 * error instead of a missing-table crash. tl_lead has no Contao Model we rely
 * on; we read directly through Doctrine DBAL (same approach as the url_rewrite
 * facade).
 */
final class Tool
{
    /**
     * Marker class for the optional host bundle. Picking the Bundle class
     * itself keeps the check reliable across bundle versions.
     */
    private const MARKER_CLASS = Terminal42LeadsBundle::class;
    private const REQUIRED_EXTENSION = 'terminal42/contao-leads';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int, limit: int, offset: int}|array{error: string, message: string, required_extension: string}
     */
    #[McpTool(
        name: 'leads_list',
        description: 'Lists form submissions (leads) from tl_lead (terminal42/contao-leads). Read-only: leads are captured from frontend form submissions and cannot be created or edited via MCP. Optional filters: form_id (the submitted form), member_id (0 = anonymous submission), language. Newest first. Each item carries the resolving form_title via a join on tl_form. Requires the bundle to be installed — returns extension_not_available otherwise.',
    )]
    public function list(int $limit = 50, int $offset = 0, ?int $form_id = null, ?int $member_id = null, ?string $language = null): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        $types = [];
        if ($form_id !== null) {
            $where[] = 'l.form_id = ?';
            $params[] = $form_id;
            $types[] = ParameterType::INTEGER;
        }
        if ($member_id !== null) {
            $where[] = 'l.member_id = ?';
            $params[] = $member_id;
            $types[] = ParameterType::INTEGER;
        }
        if ($language !== null && $language !== '') {
            $where[] = 'l.language = ?';
            $params[] = $language;
            $types[] = ParameterType::STRING;
        }

        $sql = 'SELECT l.*, f.title AS form_title FROM tl_lead l LEFT JOIN tl_form f ON f.id = l.form_id';
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        // MySQL requires integer literals for LIMIT/OFFSET; DBAL binds `?` as
        // strings by default which leads to a 42000 syntax error.
        $sql .= ' ORDER BY l.created DESC, l.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $types[] = ParameterType::INTEGER;
        $types[] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $out = array_map(Serializer::summary(...), $rows);

        return ['items' => $out, 'count' => \count($out), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'lead_get',
        description: 'Returns a single lead (form submission) by id from tl_lead, including its resolved field values from tl_lead_data (each as {field_id, name, label, value}, ordered as in the form). Read-only. Requires terminal42/contao-leads.',
    )]
    public function get(int $id): array
    {
        if (($err = $this->ensureAvailable()) !== null) {
            return $err;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT l.*, f.title AS form_title FROM tl_lead l LEFT JOIN tl_form f ON f.id = l.form_id WHERE l.id = ?',
            [$id],
            [ParameterType::INTEGER],
        );
        if ($row === false) {
            return ['error' => 'not_found', 'message' => "Lead $id not found."];
        }

        $dataRows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_lead_data WHERE pid = ? ORDER BY sorting ASC, id ASC',
            [$id],
            [ParameterType::INTEGER],
        );

        return Serializer::summary($row) + ['data' => array_map(Serializer::dataRow(...), $dataRows)];
    }

    /**
     * @return array{error: string, message: string, required_extension: string}|null
     */
    private function ensureAvailable(): ?array
    {
        if (class_exists(self::MARKER_CLASS)) {
            // Boot Contao so any framework-backed helpers are ready.
            $this->framework->initialize();
            return null;
        }

        return [
            'error' => 'extension_not_available',
            'message' => 'This tool requires the optional Contao extension "'.self::REQUIRED_EXTENSION.'", which is not installed in this project. Use installed_bundles to inspect availability.',
            'required_extension' => self::REQUIRED_EXTENSION,
        ];
    }
}
