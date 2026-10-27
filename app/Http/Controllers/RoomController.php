<?php

namespace App\Http\Controllers;

use App\Models\RoomPreviewGeneration;
use App\Http\Requests\RoomStoreRequest;
use App\Jobs\AnalyzeRoomImage;
use App\Models\RoomImage;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->to(route('preferences.edit').'#room-preview');
    }

    public function store(RoomStoreRequest $request, GeminiService $gemini, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        $data = $request->validated();
        $preference = $request->user()->preference()->first();
        if (! $preference || blank($preference->furniture_type)) {
            return redirect()->to(route('preferences.edit').'#preference-form')->withErrors(['workflow' => 'Save your furniture preferences before uploading a room image.']);
        }

        $limitKey = 'room-analysis-user:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($limitKey, 10)) {
            return back()->withInput()->withErrors(['room_image' => 'You have reached the room analysis limit. Try again in '.RateLimiter::availableIn($limitKey).' seconds.']);
        }
        RateLimiter::hit($limitKey, 3600);
        $path = $photos->storeUpload($request->file('room_image'), $request->user()->id);
        $photoMetadata = $photos->metadata($path);
        if (max($photoMetadata['width'], $photoMetadata['height']) < 600 || max($photoMetadata['width'], $photoMetadata['height']) > 1600) {
            $photos->deleteGenerated($path);
            return back()->withInput()->withErrors(['room_image' => 'The prepared room photo must be between 600 and 1600 pixels on its longest side. Select the photo again.']);
        }
        $consented = $request->boolean('gemini_consent');
        $cachedQuery = RoomImage::where('user_id', $request->user()->id)
            ->where('image_sha256', $photoMetadata['sha256']);
        if ($consented) {
            $cachedQuery->where('analysis_status', 'completed')->whereJsonContains('analysis->gemini_used', true);
        } else {
            $cachedQuery->whereIn('analysis_status', ['completed', 'fallback']);
        }
        $cachedAnalysis = $cachedQuery->latest('analyzed_at')->first();
        $width = isset($data['room_width_m']) ? (float) $data['room_width_m'] : null;
        $depth = isset($data['room_depth_m']) ? (float) $data['room_depth_m'] : null;
        $room = RoomImage::create([
            'user_id' => $request->user()->id,
            'image_path' => $path,
            'image_sha256' => $photoMetadata['sha256'],
            'image_width_px' => $photoMetadata['width'],
            'image_height_px' => $photoMetadata['height'],
            'room_width_m' => $width,
            'room_depth_m' => $depth,
            'analysis' => $gemini->roomFallback($width, $depth, $preference->furniture_type),
            'analysis_status' => 'queued',
            'analysis_requested_at' => now(),
            'gemini_consent_at' => $consented ? now() : null,
            'gemini_consent_version' => $consented ? 'room-ai-v1' : null,
        ]);
        if ($consented) $audit->record('room_photo_gemini_consent_granted', 'room_image', $room->id, ['consent_version' => 'room-ai-v1']);
        if ($cachedAnalysis) {
            $room->update(['analysis' => $cachedAnalysis->analysis, 'analysis_status' => $cachedAnalysis->analysis_status, 'analysis_error' => $cachedAnalysis->analysis_error, 'analyzed_at' => now()]);
        } elseif (! $consented || ! $gemini->isConfigured()) {
            $room->update([
                'analysis_status' => 'fallback',
                'analysis_error' => $consented
                    ? 'Gemini is not configured; safe local planning guidance is being used.'
                    : 'AI image processing was not authorized; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
        } else {
            AnalyzeRoomImage::dispatch($room->id, 'all catalog furniture');
        }
        $room->refresh();
        $message = in_array($room->analysis_status, ['completed', 'fallback'], true)
            ? 'Your room is ready and selected for recommendations.'
            : 'Your room was uploaded. FurniCraft is analyzing it in the background.';

        if (($data['return_to'] ?? null) === 'recommendations') {
            return redirect()->to(route('preferences.edit', ['room' => $room->id]).'#room-preview')->with('success', $message);
        }

        return redirect()->route('room.visualize', $room)->with('success', $message);
    }

    public function destroy(Request $request, RoomImage $roomImage, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        $roomId = $roomImage->id;
        DB::transaction(function () use ($roomImage, $photos) {
            $roomImage = RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            $photos->deleteRoomFiles($roomImage);
            $roomImage->delete();
        });
        $audit->record('room_photo_deleted', 'room_image', $roomId);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'The room photo and generated previews were deleted.', 'room_id' => $roomId]);
        }

        return redirect()->route('preferences.edit')->with('success', 'The room photo and generated previews were deleted.');
    }

    public function revokeAiConsent(Request $request, RoomImage $roomImage, RoomPhotoService $photos, PrivacyAudit $audit, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($request, $roomImage, $photos, $gemini) {
            $roomImage = RoomImage::whereKey($roomImage->id)->lockForUpdate()->firstOrFail();
            $photos->deleteGeneratedPreviews($roomImage);
            RoomPreviewGeneration::where('room_image_id', $roomImage->id)->whereIn('status', ['queued', 'processing', 'retrying'])
                ->update(['status' => 'cancelled', 'message' => 'AI processing is disabled for this photo.']);
            $analysis = $gemini->roomFallback(
                $roomImage->room_width_m ? (float) $roomImage->room_width_m : null,
                $roomImage->room_depth_m ? (float) $roomImage->room_depth_m : null,
                'all catalog furniture',
            );
            $roomImage->update([
                'gemini_consent_at' => null,
                'gemini_consent_version' => null,
                'analysis' => $analysis,
                'analysis_status' => 'fallback',
                'analysis_error' => 'AI processing disabled by you; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
        });
        $audit->record('room_photo_gemini_consent_revoked', 'room_image', $roomImage->id);

        return back()->with('success', 'AI processing is disabled for this photo. Future AI requests are stopped and generated previews were deleted.');
    }

    public function reanalyze(Request $request, RoomImage $roomImage)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_if(in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true), 422, 'Room analysis is already in progress.');

        if (! $roomImage->gemini_consent_at) {
            $message = 'AI analysis is off for this photo. Upload it again and choose AI consent if you want Gemini to analyze it; the local estimate is still available.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'status' => $roomImage->analysis_status], 422)
                : back()->with('warning', $message);
        }

        $roomImage->update([
            'analysis_status' => 'queued',
            'analysis_error' => null,
            'analysis_requested_at' => now(),
        ]);
        AnalyzeRoomImage::dispatch($roomImage->id, 'all catalog furniture');
        $roomImage->refresh();

        $message = match ($roomImage->analysis_status) {
            'completed' => 'Room analysis completed successfully.',
            'fallback' => $roomImage->analysis_error ?: 'Local room guidance is ready.',
            default => 'Room re-analysis has been queued.',
        };

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'status' => $roomImage->analysis_status])
            : back()->with('success', $message);
    }

    public function analysisStatus(Request $request, RoomImage $roomImage, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);

        if ($roomImage->analysis_status === 'queued' && (! $roomImage->gemini_consent_at || ! $gemini->isConfigured())) {
            RoomImage::whereKey($roomImage->id)->where('analysis_status', 'queued')->update([
                'analysis_status' => 'fallback',
                'analysis_error' => $roomImage->gemini_consent_at
                    ? 'Gemini is not configured; safe local planning guidance is being used.'
                    : 'AI image processing was not authorized; local planning guidance is being used.',
                'analyzed_at' => now(),
            ]);
            $roomImage->refresh();
        }

        return response()->json([
            'status' => $roomImage->analysis_status,
            'attempts' => $roomImage->analysis_attempts,
            'message' => $roomImage->analysis_error,
            'ready' => in_array($roomImage->analysis_status, ['completed', 'fallback'], true),
            'analyzed_at' => $roomImage->analyzed_at?->toIso8601String(),
        ]);
    }
}
