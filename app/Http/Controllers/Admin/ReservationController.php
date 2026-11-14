<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Http\Requests\Admin\ReservationUpdateRequest;
use App\Services\ReservationStatusNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    public function index(Request $request, \App\Services\ReservationPreview $previews, \App\Services\RoomPhotoService $photos)
    {
        $reservations = Reservation::with('user', 'product', 'recommendation.roomImage')->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))->latest()->paginate(15)->withQueryString();
        $references = $reservations->getCollection()->pluck('reference')->all();
        foreach ($reservations as $reservation) {
            $reservation->setAttribute('preview_available', $previews->available($reservation, $photos));
        }
        $notificationLogs = $references === [] ? collect() : NotificationLog::query()
            ->where('type', 'reservation_confirmed')
            ->whereIn('payload->reservation', $references)
            ->orderByDesc('id')->get()
            ->unique(fn (NotificationLog $log) => data_get($log->payload, 'reservation'))
            ->keyBy(fn (NotificationLog $log) => data_get($log->payload, 'reservation'));

        return view('admin.reservations.index', compact('reservations', 'notificationLogs'));
    }

    public function update(ReservationUpdateRequest $request, Reservation $reservation)
    {
        $data = $request->validated();
        $notifier = app(ReservationStatusNotifier::class);
        [$reservation, $log] = DB::transaction(function () use ($request, $reservation, $data, $notifier) {
            $log = null;
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($reservation->status, ['pending', 'confirmed', 'declined'], true), 422, 'This reservation is already closed.');
            if (isset($data['outcome'])) {
                abort_unless($reservation->status === 'confirmed' && $data['status'] === 'confirmed', 422, 'Only confirmed reservations can be closed out.');
                $data['status'] = $data['outcome'];
            }
            if ($request->boolean('item_ready')) {
                abort_unless($reservation->status === 'confirmed' && $data['status'] === 'confirmed', 422, 'Shop readiness can only be recorded for a confirmed reservation.');
            }
            $previousStatus = $reservation->status;
            $wasReady = $reservation->item_ready_confirmed_at !== null;
            $isReapproved = $data['status'] === 'confirmed' && $previousStatus !== 'confirmed';
            $updates = [
                'status' => $data['status'],
                'confirmed_at' => $data['status'] === 'confirmed' ? ($isReapproved ? now() : $reservation->confirmed_at) : $reservation->confirmed_at,
                'confirmed_by' => $data['status'] === 'confirmed' ? ($isReapproved ? $request->user()->id : $reservation->confirmed_by) : $reservation->confirmed_by,
                'decline_reason' => $data['status'] === 'declined' ? ($data['decline_reason'] ?? $reservation->decline_reason) : null,
            ];
            if ($data['status'] !== 'confirmed') $updates['item_ready_confirmed_at'] = null;
            if ($request->boolean('item_ready')) $updates['item_ready_confirmed_at'] = $reservation->item_ready_confirmed_at ?? now();
            if (($data['outcome'] ?? null) === 'completed') $updates['completed_at'] = now();
            if (($data['outcome'] ?? null) === 'no_show') $updates['no_show_at'] = now();
            $reservation->update($updates);
            $action = $data['outcome'] ?? ($request->boolean('item_ready') ? 'item_ready_confirmed' : $data['status']);
            $reservation->transactions()->create(['actor_id' => $request->user()->id, 'action' => $action, 'notes' => $data['notes'] ?? $data['decline_reason'] ?? null, 'metadata' => ['previous_status' => $previousStatus]]);
            if ($previousStatus !== $reservation->status) {
                $log = $notifier->record($reservation, $reservation->status);
            } elseif ($request->boolean('item_ready') && ! $wasReady) {
                $log = $notifier->record($reservation, 'ready');
            }
            return [$reservation, $log];
        });
        $notifier->dispatch($reservation, $log);
        if ($request->expectsJson() && $request->boolean('item_ready')) {
            return response()->json([
                'status' => $reservation->status,
                'item_ready_confirmed_at' => $reservation->item_ready_confirmed_at?->toIso8601String(),
                'label' => 'Shop marked ready · '.$reservation->item_ready_confirmed_at?->format('M j'),
            ]);
        }
        $statusMessage = match ($reservation->status) {
            'confirmed' => 'request approved',
            'completed' => 'completed / claimed',
            'no_show' => 'recorded as no-show',
            default => $reservation->status,
        };
        return back()->with('success', "Reservation {$reservation->reference} is now {$statusMessage}.");
    }
}
