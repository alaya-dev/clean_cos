<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Complaints\Models\Complaint;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:nouvelle,en_cours,resolue'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $complaints = Complaint::query()->with('order:id,public_reference')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('public_reference', 'like', '%'.$search.'%')->orWhere('subject', 'like', '%'.$search.'%')->orWhere('customer_name', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => $complaints]);
    }

    public function show(Complaint $complaint): JsonResponse
    {
        return response()->json(['data' => $complaint->load(['order:id,public_reference', 'notes.user:id,public_id,name', 'statusHistory.actor:id,public_id,name'])]);
    }

    public function transition(Request $request, Complaint $complaint, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $payload = $request->validate(['to_status' => ['required', 'in:en_cours,resolue']]);
        $from = DB::transaction(function () use ($complaint, $payload, $actor): string {
            $locked = Complaint::query()->whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            $allowed = ['nouvelle' => 'en_cours', 'en_cours' => 'resolue'];
            if (($allowed[$locked->status] ?? null) !== $payload['to_status']) {
                throw ValidationException::withMessages(['to_status' => 'Cette transition n’est pas autorisée.']);
            }
            $from = $locked->status;
            $locked->update(['status' => $payload['to_status'], 'resolved_at' => $payload['to_status'] === 'resolue' ? now() : null]);
            $locked->statusHistory()->create(['from_status' => $from, 'to_status' => $payload['to_status'], 'changed_by' => $actor->id, 'created_at' => now()]);

            return $from;
        });
        $complaint->refresh();
        $audit->handle('complaints.status_transitioned', $complaint, $actor, ['status' => $from], ['status' => $payload['to_status']]);

        return response()->json(['data' => $complaint]);
    }

    public function note(Request $request, Complaint $complaint, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        $payload = $request->validate(['body' => ['required', 'string', 'between:2,5000']]);
        $note = $complaint->notes()->create(['user_id' => $actor->id, 'body' => $payload['body'], 'created_at' => now()]);
        $audit->handle('complaints.note_added', $complaint, $actor, after: ['note_id' => $note->id]);

        return response()->json(['data' => $note->load('user:id,public_id,name')], 201);
    }

    public function destroy(Request $request, Complaint $complaint, RecordAuditEventAction $audit): JsonResponse
    {
        $actor = $this->actor($request);
        DB::transaction(function () use ($complaint): void {
            $locked = Complaint::query()->whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            $locked->delete();
        });
        $audit->handle('complaints.deleted', $complaint, $actor, before: ['public_reference' => $complaint->public_reference, 'status' => $complaint->status]);

        return response()->json(['data' => null]);
    }

    public function attachment(Complaint $complaint): StreamedResponse
    {
        abort_unless($complaint->attachment_path && Storage::disk('local')->exists($complaint->attachment_path), 404);

        return Storage::disk('local')->download($complaint->attachment_path, 'piece-jointe-'.$complaint->public_reference.'.webp', ['Content-Type' => $complaint->attachment_mime ?? 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
