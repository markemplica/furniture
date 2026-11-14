<?php

namespace App\Services;

use App\Models\CustomerPreference;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationService
{
    public const ALGORITHM_VERSION = 'hybrid-v6-popularity-fallback';

    public function __construct(private GeminiService $gemini) {}

    public function generate(User $user, CustomerPreference $preference, ?RoomImage $roomImage, string $furnitureType, ?Recommendation $existing = null): Recommendation
    {
        $products = FurnitureProduct::query()
            ->active()
            ->ofRecommendationType($furnitureType)
            ->matchingPreference($preference)
            ->get();

        $history = $this->affinityHistory($user);
        $roomFitContext = $this->roomFitContext($preference, $roomImage);
        $collaborativeResult = $this->collaborativeScores($user, $products);
        $rawCollaborative = $collaborativeResult['scores'];
        $rawContent = $products->mapWithKeys(fn (FurnitureProduct $product) => [
            $product->id => $this->contentScore($product, $preference, $history, $roomFitContext),
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

        // Customer recommendation summaries are local; Gemini is used separately for room photos, previews, and chat.
        $ai = $this->gemini->localRecommendationSummary(array_merge($preference->toArray(), ['furniture_type' => $furnitureType]));

        return DB::transaction(function () use ($user, $preference, $roomImage, $furnitureType, $ranked, $ai, $collaborativeResult, $existing) {
            $recommendation = $existing ?? new Recommendation;
            $recommendation->fill([
                'user_id' => $user->id,
                'customer_preference_id' => $preference->id,
                'room_image_id' => $roomImage?->id,
                'furniture_type' => $furnitureType,
                'summary' => $ai['text'],
                'algorithm_version' => self::ALGORITHM_VERSION,
                'gemini_used' => $ai['used'],
                'is_cold_start' => ! $collaborativeResult['has_similar_users'],
                'content_weight' => 0.82,
                'collaborative_weight' => 0.18,
            ]);
            $recommendation->save();
            $existingItems = $recommendation->items()->get()->keyBy('furniture_product_id');
            $rankedProductIds = [];

            foreach ($ranked as $index => $row) {
                $product = $row['product'];
                $rankedProductIds[] = $product->id;
                $reasons = collect([
                    "is a {$furnitureType} from your selected furniture type",
                    $this->matches($preference->style, $product->style) ? "matches your {$product->style} style" : null,
                    $this->matches($preference->wood_type, $product->wood_type) ? "uses your preferred {$product->wood_type} wood" : null,
                    $this->matches($preference->varnish_finish, $product->varnish_finish) ? "has the {$product->varnish_finish} finish you selected" : null,
                    ((float) $product->price >= (float) $preference->budget_min && (float) $product->price <= (float) $preference->budget_max) ? 'fits your budget range' : null,
                    $row['price_fit'] >= 0.8 ? 'priced close to the middle of your budget' : null,
                ])->filter()->values();

                $itemValues = [
                    'content_score' => $row['content'],
                    'collaborative_score' => $row['collaborative'],
                    'total_score' => $row['total'],
                    'position' => $index + 1,
                    'explanation' => $reasons->isEmpty() ? 'This is the closest active catalog match based on your combined preferences; physical availability must be confirmed with Lance Furniture Shop.' : ucfirst($reasons->implode(', ')).'.',
                ];

                $existingItem = $existingItems->get($product->id);
                if ($existingItem) {
                    $existingItem->update($itemValues);
                } else {
                    $recommendation->items()->create($itemValues + ['furniture_product_id' => $product->id]);
                }
            }

            $staleItems = $recommendation->items();
            if ($rankedProductIds !== []) {
                $staleItems->whereNotIn('furniture_product_id', $rankedProductIds);
            }
            $staleItems->delete();

            return $recommendation->load('items.product', 'preference', 'roomImage');
        });
    }

    private function contentScore(FurnitureProduct $product, CustomerPreference $preference, Collection $history, array $roomFitContext): float
    {
        $priceFit = $this->priceFit($product, $preference);
        $roomFit = $roomFitContext['weight'] > 0
            ? $this->roomFit($product, $roomFitContext['width'], $roomFitContext['depth'])
            : 0.0;
        $affinity = $this->affinity($product, $history);

        return round(100 * ((0.40 * $priceFit) + (0.35 * $roomFitContext['weight'] * $roomFit) + (0.25 * $affinity)), 3);
    }

    private function roomFitContext(CustomerPreference $preference, ?RoomImage $roomImage): array
    {
        $manualWidth = (float) $preference->room_width_m;
        $manualDepth = (float) $preference->room_depth_m;
        if ($manualWidth > 0 && $manualDepth > 0) {
            return ['width' => $manualWidth, 'depth' => $manualDepth, 'weight' => 1.0];
        }

        $analysis = $roomImage?->analysis ?? [];
        $width = (float) data_get($analysis, 'estimated_width_m');
        $depth = (float) data_get($analysis, 'estimated_depth_m');
        $confidence = strtolower(trim((string) data_get($analysis, 'dimension_confidence')));
        $weight = match ($confidence) {
            'high' => 0.75,
            'medium' => 0.5,
            default => 0.0,
        };

        if ($roomImage?->analysis_status !== 'completed' || ! data_get($analysis, 'gemini_used') || $width <= 0 || $depth <= 0) {
            $weight = 0.0;
        }

        return ['width' => $width, 'depth' => $depth, 'weight' => $weight];
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

    private function roomFit(FurnitureProduct $product, float $roomWidth, float $roomDepth): float
    {
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
        $events = DB::query()->fromSub(
            DB::table('furniture_view_events')
                ->where('user_id', $user->id)
                ->select('id', 'furniture_product_id', 'created_at')
                ->selectRaw('1 as weight')
                ->unionAll(DB::table('favorites')
                    ->where('user_id', $user->id)
                    ->select('id', 'furniture_product_id', 'created_at')
                    ->selectRaw('2 as weight')),
            'history'
        )->orderByDesc('created_at')->orderByDesc('id')->orderByDesc('weight')->limit(200)->get();

        if ($events->isEmpty()) {
            return collect();
        }

        $products = FurnitureProduct::query()
            ->whereIn('id', $events->pluck('furniture_product_id')->unique())
            ->get(['id', 'style', 'wood_type', 'varnish_finish'])
            ->keyBy('id');

        return $events->map(fn ($event) => [
            'product' => $products->get($event->furniture_product_id),
            'weight' => (int) $event->weight,
        ])->filter(fn (array $entry) => $entry['product'] !== null)->values();
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
        $scores = $products->mapWithKeys(fn (FurnitureProduct $product) => [$product->id => 0.0])->all();
        $ownProductQuery = DB::table('favorites')
            ->where('user_id', $user->id)
            ->select('furniture_product_id')
            ->union(DB::table('reservations')
                ->where('user_id', $user->id)
                ->where('status', '!=', 'cancelled')
                ->select('furniture_product_id'));
        $ownProductIds = DB::query()->fromSub($ownProductQuery, 'own_products')->pluck('furniture_product_id');
        $ownInteractions = array_fill_keys($ownProductIds->map(fn ($id) => (int) $id)->all(), true);
        if ($ownInteractions === []) {
            return ['scores' => $this->popularityScores($scores), 'has_similar_users' => false];
        }

        $similarUsers = DB::table('favorites')
            ->where('user_id', '!=', $user->id)
            ->whereIn('furniture_product_id', clone $ownProductQuery)
            ->select('user_id')
            ->union(DB::table('reservations')
                ->where('user_id', '!=', $user->id)
                ->where('status', '!=', 'cancelled')
                ->whereIn('furniture_product_id', clone $ownProductQuery)
                ->select('user_id'));
        $favoriteInteractions = DB::table('favorites')
            ->whereIn('user_id', clone $similarUsers)
            ->select('user_id', 'furniture_product_id')->distinct()->get();
        $reservationInteractions = DB::table('reservations')
            ->where('status', '!=', 'cancelled')
            ->whereIn('user_id', clone $similarUsers)
            ->select('user_id', 'furniture_product_id')->distinct()->get();

        $interactionsByUser = [];
        foreach ($favoriteInteractions->concat($reservationInteractions) as $interaction) {
            $interactionsByUser[$interaction->user_id][(int) $interaction->furniture_product_id] = true;
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

        return [
            'scores' => $hasSimilarUsers ? $scores : $this->popularityScores($scores),
            'has_similar_users' => $hasSimilarUsers,
        ];
    }

    private function popularityScores(array $scores): array
    {
        if ($scores === []) {
            return $scores;
        }

        $productIds = array_keys($scores);
        foreach (['favorites', 'reservations'] as $table) {
            $query = DB::table($table)->whereIn('furniture_product_id', $productIds);
            if ($table === 'reservations') {
                $query->where('status', '!=', 'cancelled');
            }

            foreach ($query->select('furniture_product_id')->selectRaw('COUNT(*) as interaction_count')
                ->groupBy('furniture_product_id')->get() as $interaction) {
                $scores[(int) $interaction->furniture_product_id] += (float) $interaction->interaction_count;
            }
        }

        return $scores;
    }

    private function matches(?string $expected, ?string $actual): bool
    {
        return blank($expected) || strcasecmp(trim($expected), trim((string) $actual)) === 0;
    }
}
