<?php

namespace App\Services;

use App\Models\CustomerPreference;
use App\Models\Favorite;
use App\Models\FurnitureProduct;
use App\Models\FurnitureViewEvent;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationService
{
    public function __construct(private GeminiService $gemini) {}

    public function generate(User $user, CustomerPreference $preference, ?RoomImage $roomImage, string $furnitureType, ?Recommendation $existing = null): Recommendation
    {
        $products = FurnitureProduct::query()
            ->active()
            ->ofRecommendationType($furnitureType)
            ->matchingPreference($preference)
            ->get();

        $history = $this->affinityHistory($user);
        $collaborativeResult = $this->collaborativeScores($user, $products);
        $rawCollaborative = $collaborativeResult['scores'];
        $rawContent = $products->mapWithKeys(fn (FurnitureProduct $product) => [
            $product->id => $this->contentScore($product, $preference, $history),
        ])->all();
        $contentScores = $this->normalizeScores($rawContent);
        $collaborativeScores = $this->normalizeScores($rawCollaborative, allZeroAsZero: true);

        $ranked = $products->map(function (FurnitureProduct $product) use ($preference, $contentScores, $collaborativeScores) {
            $content = $contentScores[$product->id] ?? 50.0;
            $collaborative = $collaborativeScores[$product->id] ?? 0.0;
            $total = round((0.82 * $content) + (0.18 * $collaborative), 3);

            return [
                'product' => $product,
                'content' => $content,
                'collaborative' => $collaborative,
                'total' => $total,
                'price_fit' => $this->priceFit($product, $preference),
            ];
        })->sort(function (array $left, array $right) {
            return ($right['total'] <=> $left['total'])
                ?: ($right['content'] <=> $left['content'])
                ?: ($left['product']->id <=> $right['product']->id);
        })->take(8)->values();

        $ai = $this->gemini->localRecommendationSummary(array_merge($preference->toArray(), ['furniture_type' => $furnitureType]));

        return DB::transaction(function () use ($user, $preference, $roomImage, $furnitureType, $ranked, $ai, $collaborativeResult, $existing) {
            $recommendation = $existing ?? new Recommendation;
            $recommendation->fill([
                'user_id' => $user->id,
                'customer_preference_id' => $preference->id,
                'room_image_id' => $roomImage?->id,
                'furniture_type' => $furnitureType,
                'summary' => $ai['text'],
                'algorithm_version' => 'hybrid-v4-normalized',
                'gemini_used' => $ai['used'],
                'is_cold_start' => ! $collaborativeResult['has_similar_users'],
                'content_weight' => 0.82,
                'collaborative_weight' => 0.18,
            ]);
            $recommendation->save();
            $recommendation->items()->delete();

            foreach ($ranked as $index => $row) {
                $product = $row['product'];
                $reasons = collect([
                    "is a {$furnitureType} from your selected furniture type",
                    $this->matches($preference->style, $product->style) ? "matches your {$product->style} style" : null,
                    $this->matches($preference->wood_type, $product->wood_type) ? "uses your preferred {$product->wood_type} wood" : null,
                    $this->matches($preference->varnish_finish, $product->varnish_finish) ? "has the {$product->varnish_finish} finish you selected" : null,
                    ((float) $product->price >= (float) $preference->budget_min && (float) $product->price <= (float) $preference->budget_max) ? 'fits your budget range' : null,
                    $row['price_fit'] >= 0.8 ? 'priced close to the middle of your budget' : null,
                ])->filter()->values();

                $recommendation->items()->create([
                    'furniture_product_id' => $product->id,
                    'content_score' => $row['content'],
                    'collaborative_score' => $row['collaborative'],
                    'total_score' => $row['total'],
                    'position' => $index + 1,
                    'explanation' => $reasons->isEmpty() ? 'This is the closest active catalog match based on your combined preferences; physical availability must be confirmed with Lance Furniture Shop.' : ucfirst($reasons->implode(', ')).'.',
                ]);
            }

            return $recommendation->load('items.product', 'preference', 'roomImage');
        });
    }

    private function contentScore(FurnitureProduct $product, CustomerPreference $preference, Collection $history): float
    {
        $priceFit = $this->priceFit($product, $preference);
        $roomFit = $this->roomFit($product, $preference);
        $affinity = $this->affinity($product, $history);

        return round(100 * ((0.40 * $priceFit) + (0.35 * $roomFit) + (0.25 * $affinity)), 3);
    }

    private function priceFit(FurnitureProduct $product, CustomerPreference $preference): float
    {
        $minimum = (float) $preference->budget_min;
        $maximum = (float) $preference->budget_max;

        if ($minimum === $maximum) {
            return 1.0;
        }

        $midpoint = ($minimum + $maximum) / 2;
        $halfRange = max(1.0, ($maximum - $minimum) / 2);

        return 1 - min(1, abs((float) $product->price - $midpoint) / $halfRange);
    }

    private function roomFit(FurnitureProduct $product, CustomerPreference $preference): float
    {
        $roomWidth = (float) $preference->room_width_m;
        $roomDepth = (float) $preference->room_depth_m;
        $productWidth = (float) $product->width_cm;
        $productDepth = (float) $product->depth_cm;

        if ($roomWidth <= 0 || $roomDepth <= 0 || $productWidth <= 0 || $productDepth <= 0) {
            return 0.5;
        }

        if (($productWidth / 100) > ($roomWidth * 0.85) || ($productDepth / 100) > ($roomDepth * 0.85)) {
            return 0.0;
        }

        $ratio = (($productWidth / 100) * ($productDepth / 100)) / ($roomWidth * $roomDepth);

        if ($ratio < 0.15) {
            return max(0.4, min(1.0, 0.4 + (($ratio - 0.05) / 0.10) * 0.6));
        }

        if ($ratio <= 0.55) {
            return 1.0;
        }

        return max(0.4, min(1.0, 1.0 - (($ratio - 0.55) / 0.15) * 0.6));
    }

    private function affinity(FurnitureProduct $product, Collection $history): float
    {
        if ($history->isEmpty()) {
            return 0.5;
        }

        $dimensions = ['style', 'wood_type', 'varnish_finish'];
        $scores = [];

        foreach ($dimensions as $dimension) {
            $totalWeight = 0;
            $matchedWeight = 0;
            $candidateValue = trim((string) $product->{$dimension});

            foreach ($history as $entry) {
                $historicalValue = trim((string) ($entry['product']?->{$dimension} ?? ''));
                if ($candidateValue === '' || $historicalValue === '') {
                    continue;
                }

                $weight = (int) $entry['weight'];
                $totalWeight += $weight;
                if (strcasecmp($candidateValue, $historicalValue) === 0) {
                    $matchedWeight += $weight;
                }
            }

            $scores[] = $totalWeight > 0 ? $matchedWeight / $totalWeight : 0.5;
        }

        return array_sum($scores) / count($scores);
    }

    private function affinityHistory(User $user): Collection
    {
        $productColumns = 'id,style,wood_type,varnish_finish';
        $views = FurnitureViewEvent::query()
            ->where('user_id', $user->id)
            ->with(['product:'.$productColumns])
            ->get()
            ->map(fn (FurnitureViewEvent $event) => ['product' => $event->product, 'weight' => 1]);
        $favorites = Favorite::query()
            ->where('user_id', $user->id)
            ->with(['product:'.$productColumns])
            ->get()
            ->map(fn (Favorite $favorite) => ['product' => $favorite->product, 'weight' => 2]);

        return $views->concat($favorites)->filter(fn (array $entry) => $entry['product'] !== null)->values();
    }

    private function normalizeScores(array $scores, bool $allZeroAsZero = false): array
    {
        if ($scores === []) {
            return [];
        }

        $minimum = min($scores);
        $maximum = max($scores);

        if (abs($maximum - $minimum) < 0.0000001) {
            $normalized = $allZeroAsZero && abs((float) $maximum) < 0.0000001 ? 0.0 : 50.0;
            return array_fill_keys(array_keys($scores), $normalized);
        }

        $range = $maximum - $minimum;

        return array_map(fn ($score) => round((((float) $score - $minimum) / $range) * 100, 3), $scores);
    }

    public function matchesPreferences(FurnitureProduct $product, CustomerPreference $preference): bool
    {
        return $product->status === 'active'
            && (float) $product->price >= (float) $preference->budget_min
            && (float) $product->price <= (float) $preference->budget_max
            && $this->matches($preference->wood_type, $product->wood_type)
            && $this->matches($preference->varnish_finish, $product->varnish_finish)
            && $this->matches($preference->style, $product->style);
    }

    public function closestSuggestions(CustomerPreference $preference, string $type): Collection
    {
        $products = FurnitureProduct::query()->active()->ofRecommendationType($type);
        $matchingProducts = (clone $products)->matchingPreference($preference)->select('id');

        return $products->whereNotIn('id', $matchingProducts)->get()
            ->map(function ($product) use ($preference) {
                $differences = [];
                foreach (['wood_type' => 'Wood', 'style' => 'Style', 'varnish_finish' => 'Finish'] as $field => $label) {
                    if (! $this->matches($preference->$field, $product->$field)) {
                        $differences[] = $label.': '.($product->$field ?: 'unspecified').' instead of '.$preference->$field;
                    }
                }
                $price = (float) $product->price;
                $gap = max(0, $price - (float) $preference->budget_max, (float) $preference->budget_min - $price);
                if ($gap > 0) {
                    $differences[] = 'PHP '.number_format($gap, 2).($price > (float) $preference->budget_max ? ' above your maximum budget' : ' below your minimum budget');
                }
                return ['product' => $product, 'differences' => $differences,
                    'distance' => count($differences) + $gap / max(1, (float) $preference->budget_max)];
            })->sortBy(fn ($row) => [$row['distance'], (float) $row['product']->price, $row['product']->id])->take(3)->values();
    }

    private function collaborativeScores(User $user, Collection $products): array
    {
        $favoriteInteractions = Favorite::query()->select('user_id', 'furniture_product_id')->distinct()->get();
        $reservationInteractions = Reservation::query()
            ->where('status', '!=', 'cancelled')
            ->select('user_id', 'furniture_product_id')
            ->distinct()
            ->get();

        $interactionsByUser = [];
        foreach ($favoriteInteractions->concat($reservationInteractions) as $interaction) {
            $interactionsByUser[$interaction->user_id][(int) $interaction->furniture_product_id] = true;
        }

        $ownInteractions = $interactionsByUser[$user->id] ?? [];
        $scores = $products->mapWithKeys(fn (FurnitureProduct $product) => [$product->id => 0.0])->all();
        if ($ownInteractions === []) {
            return ['scores' => $scores, 'has_similar_users' => false];
        }

        $candidateIds = array_fill_keys($products->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $hasSimilarUsers = false;

        foreach ($interactionsByUser as $otherUserId => $otherInteractions) {
            if ((int) $otherUserId === (int) $user->id || $otherInteractions === []) {
                continue;
            }

            $intersection = count(array_intersect_key($ownInteractions, $otherInteractions));
            if ($intersection === 0) {
                continue;
            }

            $similarity = $intersection / (count($ownInteractions) + count($otherInteractions) - $intersection);
            if ($similarity <= 0) {
                continue;
            }

            $hasSimilarUsers = true;
            foreach ($otherInteractions as $productId => $_) {
                if (isset($candidateIds[$productId]) && ! isset($ownInteractions[$productId])) {
                    $scores[$productId] += $similarity;
                }
            }
        }

        return ['scores' => $scores, 'has_similar_users' => $hasSimilarUsers];
    }

    private function matches(?string $expected, ?string $actual): bool
    {
        return blank($expected) || strcasecmp(trim($expected), trim((string) $actual)) === 0;
    }
}
