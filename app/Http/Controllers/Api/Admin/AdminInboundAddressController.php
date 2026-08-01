<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InboundAddress;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Per-address inbound routing management for org projects.
 *
 *   GET    /api/admin/projects/{slug}/inbound-addresses
 *   PUT    /api/admin/projects/{slug}/inbound-addresses      { address, label?, metadata? }
 *   DELETE /api/admin/projects/{slug}/inbound-addresses/{address}
 */
class AdminInboundAddressController extends Controller
{
    public function index(Request $request, string $slug): JsonResponse
    {
        $project = $this->projectOr404($slug);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return response()->json([
            'addresses' => InboundAddress::query()
                ->where('project_id', $project->id)
                ->orderBy('address')
                ->get(['address', 'label', 'metadata', 'updated_at']),
        ]);
    }

    public function upsert(Request $request, string $slug): JsonResponse
    {
        $project = $this->projectOr404($slug);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $validated = $request->validate([
            'address' => ['required', 'string', 'max:320', 'email:strict'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $address = Str::lower(trim($validated['address']));

        $existing = InboundAddress::query()->where('address', $address)->first();
        if ($existing && $existing->project_id !== $project->id) {
            return response()->json([
                'message' => 'This address is registered to another project.',
                'code' => 'address_conflict',
            ], 409);
        }

        $record = InboundAddress::query()->updateOrCreate(
            ['address' => $address],
            [
                'project_id' => $project->id,
                'label' => $validated['label'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
            ],
        );

        return response()->json([
            'address' => $record->only(['address', 'label', 'metadata']),
        ], $record->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $slug, string $address): JsonResponse
    {
        $project = $this->projectOr404($slug);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $deleted = InboundAddress::query()
            ->where('project_id', $project->id)
            ->where('address', Str::lower(trim($address)))
            ->delete();

        return response()->json(['deleted' => $deleted > 0]);
    }

    private function projectOr404(string $slug): Project|JsonResponse
    {
        $workspaceSlug = (string) config('larasend.platform.workspace_slug');
        $workspace = $workspaceSlug === '' ? null : Workspace::query()->where('slug', $workspaceSlug)->first();
        if (! $workspace) {
            return response()->json(['message' => 'Platform workspace is not set up.', 'code' => 'platform_not_ready'], 404);
        }

        $project = $workspace->projects()->where('slug', Str::lower($slug))->first();
        if (! $project) {
            return response()->json(['message' => 'Unknown organization project.'], 404);
        }

        return $project;
    }
}
