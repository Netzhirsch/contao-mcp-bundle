<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle\Controller;

use Netzhirsch\ContaoMcpBundle\Tool\File\UploadSessions;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Takes the raw bytes of an upload opened with `file_upload_begin`, so they do
 * not have to pass through the model.
 *
 * Every other way to hand over bytes we have goes through a tool argument: the
 * client turns the file into base64 and writes it into a call. For a model that
 * is the weak point of the whole upload — the argument gets long, gets cut off,
 * or a character changes, and the file arrives broken or not at all. A client
 * that can run a command has the file on disk already; it should simply send
 * it. That is this endpoint: one `curl --data-binary @file` and the bytes are
 * in the session buffer, whatever their size.
 *
 * What it does NOT do is store anything in `files/`. It only fills the buffer
 * of a session that an authenticated MCP caller opened. Every check — rights,
 * name, extension, size, content, SVG sanitising, overwrite — still runs in
 * `file_upload_finish`, which goes through the normal MCP authentication and
 * permission gate. So this endpoint needs no OAuth of its own; it needs proof
 * that the caller is the one the session was opened for:
 *
 *   - `Authorization: Bearer <token>` — 256 random bits handed out once, in
 *     the answer to `file_upload_begin`. Only its hash is on disk. A wrong or
 *     missing token gets the same 404 as an unknown id, so the endpoint does
 *     not tell anyone which ids exist.
 *   - `Upload-Offset: <n>` — the bytes already received. The body is appended
 *     only when that matches, as in the tus resumable-upload protocol, so a
 *     retry after a dropped connection cannot add the same bytes twice. A
 *     mismatch answers 409 with the offset to resume at.
 *   - The body may not take the buffer past the size declared at begin, which
 *     `begin` already checked against tl_settings.maxFileSize.
 *
 * The body is streamed to disk in blocks: a PUT is not subject to
 * post_max_size, and the size of the file does not decide how much memory the
 * request needs. What still applies is the web server's own body limit
 * (nginx `client_max_body_size`, 1 MB by default) — that answers 413 before
 * PHP runs, and the way around it is several PUTs with increasing offsets.
 *
 * Route: PUT /<path>/upload/<id> (/mcp/upload/<id> by default), matched by
 * {@see \Netzhirsch\ContaoMcpBundle\EventListener\McpEndpointRouteListener}.
 */
final class McpUploadController
{
    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $id = (string) $request->attributes->get('upload_id', '');
        $sessions = new UploadSessions($this->projectDir);
        $token = self::bearerToken($request);

        $manifest = $sessions->load($id);

        if ($manifest === null || !UploadSessions::tokenMatches($manifest, $token)) {
            return self::notFound();
        }

        $offset = $request->headers->get('Upload-Offset');

        if ($offset === null || preg_match('/^\d+$/', $offset) !== 1) {
            return self::json([
                'error' => 'offset_required',
                'message' => 'Send the header Upload-Offset with the number of bytes already received: 0 for the first request.',
                'received_bytes' => (int) $manifest['received_bytes'],
            ], 400);
        }

        $total = (int) $manifest['total_size_bytes'];
        $declared = $request->headers->get('Content-Length');

        // Refused before the body is read, when the client says up front that
        // it will not fit.
        if ($declared !== null && preg_match('/^\d+$/', $declared) === 1 && (int) $offset + (int) $declared > $total) {
            return self::json([
                'error' => 'size_exceeded',
                'message' => sprintf('Offset %d plus %d bytes is more than the %d declared at file_upload_begin. Nothing was written.', (int) $offset, (int) $declared, $total),
                'received_bytes' => (int) $manifest['received_bytes'],
            ], 413);
        }

        try {
            $result = $sessions->locked($id, function (array $manifest) use ($sessions, $request, $token, $offset, $total): array {
                // Checked again under the lock: the session may have finished
                // or been aborted since the first look.
                if (!UploadSessions::tokenMatches($manifest, $token)) {
                    return ['status' => 404];
                }

                $received = (int) $manifest['received_bytes'];

                if ($received !== (int) $offset) {
                    return ['status' => 409, 'body' => [
                        'error' => 'offset_mismatch',
                        'message' => sprintf('%d bytes have arrived so far, not %d. Send the rest with Upload-Offset: %d.', $received, (int) $offset, $received),
                        'received_bytes' => $received,
                    ]];
                }

                $stream = $request->getContent(true);
                $updated = $sessions->appendStream($manifest, $stream, $total);

                if ($updated === null) {
                    return ['status' => 413, 'body' => [
                        'error' => 'size_exceeded',
                        'message' => sprintf('The body would take the upload past the %d bytes declared at file_upload_begin. Nothing from this request was kept.', $total),
                        'received_bytes' => $received,
                    ]];
                }

                $now = (int) $updated['received_bytes'];

                return ['status' => 200, 'body' => [
                    'accepted' => true,
                    'received_bytes' => $now,
                    'remaining_bytes' => $total - $now,
                    'complete' => $now === $total,
                    'next' => $now === $total
                        ? 'Call file_upload_finish with this upload_id.'
                        : sprintf('Send the rest with Upload-Offset: %d.', $now),
                ]];
            });
        } catch (\Throwable $e) {
            $this->logger->error('MCP direct upload failed.', ['upload_id' => $id, 'exception' => $e]);

            return self::json(['error' => 'upload_failed', 'message' => 'The bytes could not be stored. Nothing from this request was kept; try again.'], 500);
        }

        if ($result === null || $result['status'] === 404) {
            return self::notFound();
        }

        $response = self::json($result['body'], $result['status']);
        $response->headers->set('Upload-Offset', (string) ($result['body']['received_bytes'] ?? 0));

        return $response;
    }

    private static function bearerToken(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return preg_match('/^Bearer\s+([0-9a-f]{64})$/i', trim($header), $m) === 1 ? strtolower($m[1]) : '';
    }

    private static function notFound(): JsonResponse
    {
        return self::json([
            'error' => 'upload_not_found',
            'message' => 'No open upload with that id and token. It was never started, already finished, expired, or the token is wrong.',
        ], 404);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
