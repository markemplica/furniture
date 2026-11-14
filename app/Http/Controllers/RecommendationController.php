<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Http\Requests\RecommendationGenerateRequest;
use App\Services\FurniturePlacementService;
use App\Services\RecommendationService;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function index(Request $request)
    {
        $recommendations = $request->user()->recommendations()->withCount('items')->latest()->paginate(10);

        return view('recommendations.index', compact('recommendations'));
    }

    public function store(RecommendationGenerateRequest $request, RecommendationService $service)
    {
        $preference = $request->user()->preference()->first();
        if (! $preference || blank($preference->furniture_type)) {
            return redirect()->to(route('preferences.edit').'#preference-form')->withErrors(['workflow' => 'Save your furniture preferences before generating recommendations.']);
        }

        $data = $request->validated();

        $room = !empty($data['room_image_id']) ? $request->user()->roomImages()->findOrFail($data['room_image_id']) : null;
        if ($room && in_array($room->analysis_status, ['queued', 'processing', 'retrying'], true)) {
            return redirect()->to(route('preferences.edit', ['room' => $room->id]).'#room-preview')
                ->withErrors(['room_image_id' => 'Room analysis is still running. Recommendations will be available as soon as it finishes.']);
        }
        $recommendation = $service->generate($request->user(), $preference, $room, $preference->furniture_type);

        return redirect()->route('recommendations.show', $recommendation)->with('success', $room ? 'Your recommendations and room preview are ready.' : 'Your recommendations are ready.');
    }

    public function show(Request $request, Recommendation $recommendation, FurniturePlacementService $placementService, RecommendationService $ranking)
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);
        $recommendation->load('preference');
        if ($recommendation->preference === null) {
            return redirect()->route('preferences.edit')->withErrors([
                'workflow' => 'Save your furniture preferences before viewing recommendations.',
            ]);
        }

        $recommendation->load('items.product', 'messages', 'preference', 'roomImage');
        if ($recommendation->algorithm_version !== RecommendationService::ALGORITHM_VERSION) {
            $ranking->generate($request->user(), $recommendation->preference, $recommendation->roomImage, $recommendation->furniture_type, $recommendation);
            $recommendation->refresh()->load('items.product', 'messages', 'preference', 'roomImage');
        }
        $recommendation->setRelation('items', $recommendation->items()
            ->whereHas('product', fn ($products) => $products->active()->matchingPreference($recommendation->preference))
            ->with('product')
            ->get());
        $room = $recommendation->roomImage;
        $placements = $room
            ? $recommendation->items->mapWithKeys(fn ($item) => [
                $item->product->id => $placementService->forProduct(
                    $room->analysis ?? [],
                    $item->product,
                ),
            ])
            : collect();

        $suggestions = $recommendation->items->isEmpty()
            ? $ranking->closestSuggestions($recommendation->preference, $recommendation->furniture_type)
            : collect();
        return view('recommendations.show', compact('recommendation', 'placements', 'suggestions'));
    }
}
