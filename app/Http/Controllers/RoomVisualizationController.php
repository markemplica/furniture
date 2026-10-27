<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyRoomPreviewRequest;
use App\Jobs\AnalyzeRoomImage;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\RoomImage;
use App\Models\RoomPreviewGeneration;
use App\Services\FurniturePlacementService;
use App\Services\GeminiService;
use App\Services\PrivacyAudit;
use App\Services\RoomPhotoService;
use Illuminate\Http\Request;

class RoomVisualizationController extends Controller
{
    public function visualize(Request $request, RoomImage $roomImage, GeminiService $gemini, FurniturePlacementService $placementService, RoomPhotoService $photos, PrivacyAudit $audit)
    {
        abort_unless($roomImage->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        $recommendation = null;

        if ($request->filled('recommendation')) {
            $recommendation = Recommendation::with('items.product')
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('recommendation'));

            abort_unless($recommendation->room_image_id === $roomImage->id, 403);
        }

        $products = FurnitureProduct::where('status', 'active')->orderBy('name')->get();
        $firstRecommendedProduct = $recommendation?->items->first()?->product;
        $selectedProduct = $request->filled('product')
            ? $products->firstWhere('id', $request->integer('product'))
            : ($firstRecommendedProduct ?? $products->first());

        abort_if($request->filled('product') && ! $selectedProduct, 404);

        $analysis = $roomImage->analysis ?? [];

        $zonesMissing = blank($analysis['candidate_zones'] ?? null)
            || (int) data_get($analysis, 'placement_profile_version', 0) < 2;
        $analysisRunning = in_array($roomImage->analysis_status, ['queued', 'processing', 'retrying'], true);
        $canAnalyzeZones = ! $roomImage->analysis_requested_at || $roomImage->analysis_requested_at->lt(now()->subMinutes(10));

        if (! $request->user()->isAdmin() && $roomImage->gemini_consent_at && $selectedProduct && $zonesMissing && $gemini->isConfigured() && ! $analysisRunning && $canAnalyzeZones) {
            $roomImage->update([
                'analysis_status' => 'queued',
                'analysis_error' => null,
                'analysis_requested_at' => now(),
            ]);
            AnalyzeRoomImage::dispatch($roomImage->id, 'all catalog furniture');
            $roomImage->refresh();
        }

        $selectedPlacement = $selectedProduct
            ? $placementService->forProduct(
                $roomImage->analysis ?? [],
                $selectedProduct,
                $roomImage->room_width_m ? (float) $roomImage->room_width_m : null,
                $roomImage->room_depth_m ? (float) $roomImage->room_depth_m : null,
            )
            : null;

        return view('room.visualize', compact('roomImage', 'selectedProduct', 'recommendation', 'selectedPlacement'));
    }

    public function verifyPreview(VerifyRoomPreviewRequest $request, RoomImage $roomImage, FurnitureProduct $product, GeminiService $gemini)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_unless($roomImage->gemini_consent_at && $gemini->isConfigured(), 403, 'AI preview checking was not enabled for this photo.');
        abort_unless($product->status === 'active', 404);
        abort_unless($product->width_cm && $product->depth_cm && $product->height_cm, 422, 'Dimensions not available for this item.');

        $data = $request->validated();

        $previewBase64 = preg_replace('/^data:[^,]*;base64,/i', '', $data['preview'], 1);
        $previewBytes = base64_decode($previewBase64, true);

        if ($previewBytes === false) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preview' => 'The preview image must contain valid base64 data.',
            ]);
        }

        if (strlen($previewBytes) > 2_000_000) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preview' => 'The preview image must be no larger than 2 MB.',
            ]);
        }

        $imageInfo = @getimagesizefromstring($previewBytes);
        if ($imageInfo === false || ! in_array($imageInfo['mime'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'preview' => 'The preview must be a PNG, JPEG, or WebP image.',
            ]);
        }

        $result = $gemini->verifyRoomPlacement($roomImage->image_path, $previewBase64, $data['placement']);

        $generation = RoomPreviewGeneration::firstOrNew([
            'room_image_id' => $roomImage->id,
            'furniture_product_id' => $product->id,
        ]);
        $history = $generation->placement_json ?? ['iterations' => []];
        $history['iterations'][] = [
            'placement' => $data['placement'],
            'verifier' => $result,
            'checked_at' => now()->toIso8601String(),
        ];
        $history['iterations'] = array_slice($history['iterations'], -3);
        $generation->fill([
            'status' => 'completed',
            'attempts' => count($history['iterations']),
            'placement_json' => $history,
            'message' => $result['valid'] ? 'Automatic visual check completed.' : 'Safe local placement retained; AI visual check was unavailable.',
        ])->save();

        return response()->json($result);
    }

    public function previewStatus(Request $request, RoomImage $roomImage, int $product)
    {
        abort_unless($roomImage->user_id === $request->user()->id, 403);
        abort_unless($roomImage->gemini_consent_at, 404);
        $generation = RoomPreviewGeneration::where('room_image_id', $roomImage->id)
            ->where('furniture_product_id', $product)->firstOrFail();
        $path = data_get($roomImage->analysis, 'generated_previews.'.$product.'.path');

        return response()->json([
            'status' => $generation->status,
            'message' => $generation->message,
            'preview_url' => $generation->status === 'completed' && $path
                ? route('room-images.preview', [$roomImage, $product])
                : null,
        ]);
    }
}
