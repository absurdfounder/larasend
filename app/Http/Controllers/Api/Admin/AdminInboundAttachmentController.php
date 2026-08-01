<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InboundEmail;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Streams one received attachment to Trooper's authenticated inbox proxy.
 * The admin-token middleware authenticates Trooper; the project slug and
 * inbound-email relationship keep the lookup tenant scoped.
 */
class AdminInboundAttachmentController extends Controller
{
    public function __invoke(
        string $slug,
        InboundEmail $inboundEmail,
        int $index,
        MailMimeParser $parser,
    ): Response {
        $workspaceSlug = (string) config('larasend.platform.workspace_slug');
        $workspace = $workspaceSlug === '' ? null : Workspace::query()->where('slug', $workspaceSlug)->first();
        abort_unless($workspace, 404);

        $project = $workspace->projects()->where('slug', Str::lower($slug))->first();
        abort_unless($project && $inboundEmail->project_id === $project->id, 404);
        abort_unless(Storage::disk($inboundEmail->mime_disk)->exists($inboundEmail->mime_path), 404);

        $message = $parser->parse(
            Storage::disk($inboundEmail->mime_disk)->get($inboundEmail->mime_path),
            autoClose: true,
        );
        $parts = $message->getAllAttachmentParts();
        abort_unless(isset($parts[$index]), 404);

        $part = $parts[$index];
        $filename = $part->getFilename() ?: "attachment-{$index}";
        $filename = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $filename) ?: "attachment-{$index}";
        $content = (string) $part->getContent();

        return response($content, 200, [
            'Content-Type' => $part->getContentType() ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
