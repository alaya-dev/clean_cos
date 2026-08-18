<?php

namespace App\Http\Controllers\Api;

use App\Domain\Commerce\Models\Order;
use App\Domain\Complaints\Models\Complaint;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubmitComplaintRequest;
use App\Support\Media\SecureImageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PublicComplaintController extends Controller
{
    public function __invoke(SubmitComplaintRequest $request, SecureImageProcessor $images): JsonResponse
    {
        if ((string) $request->input('website') !== '') {
            return $this->success(null);
        }
        $payload = $request->validated();
        $phone = preg_replace('/[^0-9+]/', '', $payload['customer_phone']) ?? $payload['customer_phone'];
        $order = isset($payload['order_reference']) ? Order::query()->where('public_reference', $payload['order_reference'])->where('customer_phone', $phone)->first() : null;
        $attachment = $request->hasFile('attachment') ? $images->store($request->file('attachment'), 'local', 'complaints', 20, 8000) : null;
        try {
            $complaint = DB::transaction(function () use ($payload, $phone, $order, $attachment): Complaint {
                $complaint = Complaint::query()->create([
                    'order_id' => $order?->id, 'customer_name' => trim($payload['customer_name']), 'customer_phone' => $phone,
                    'subject' => trim($payload['subject']), 'description' => trim($payload['description']), 'status' => 'nouvelle',
                    'attachment_path' => $attachment['path'] ?? null, 'attachment_mime' => $attachment['mime'] ?? null,
                    'attachment_size' => $attachment['size'] ?? null, 'consent_at' => now(),
                ]);
                $complaint->statusHistory()->create(['from_status' => null, 'to_status' => 'nouvelle', 'created_at' => now()]);

                return $complaint;
            });
        } catch (Throwable $exception) {
            if (isset($attachment['path'])) {
                Storage::disk('local')->delete($attachment['path']);
            }
            throw $exception;
        }

        return $this->success($complaint);
    }

    private function success(?Complaint $complaint): JsonResponse
    {
        $reference = $complaint === null ? (string) str()->ulid() : $complaint->public_reference;

        return response()->json(['data' => ['public_reference' => $reference, 'status' => 'nouvelle', 'submitted_at' => now()->toIso8601String()], 'message' => 'Votre réclamation a été envoyée.'], 201);
    }
}
