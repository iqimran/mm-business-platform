<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Http\Resources\AuditLogResource;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    /**
     * Global users see every entry; branch-scoped users only entries of their accessible branches.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('audit.view');

        $filters = $request->validate([
            'action' => ['sometimes', 'string', 'max:100'],
            'entity_type' => ['sometimes', 'string', 'max:100'],
            'entity_id' => ['sometimes', 'string', 'max:64'],
            'user_id' => ['sometimes', 'string', 'max:26'],
            'branch_id' => ['sometimes', 'string', 'max:26'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $actor = $request->user();

        $logs = AuditLog::query()
            ->with(['user:id,name,email', 'branch:id,code,name'])
            ->when(! $actor->canAccessAllBranches(), fn ($q) => $q->whereIn('branch_id', $actor->assignedActiveBranchIdsQuery()))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($logs, AuditLogResource::class);
    }
}
