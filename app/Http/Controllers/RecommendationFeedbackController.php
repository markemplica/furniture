<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Models\RecommendationItem;
use App\Http\Requests\RecommendationFeedbackRequest;
use Illuminate\Http\Request;

class RecommendationFeedbackController extends Controller
{
    public function update(RecommendationFeedbackRequest $request, Recommendation $recommendation, RecommendationItem $recommendationItem)
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);
        abort_unless($recommendationItem->recommendation_id === $recommendation->id, 404);

        $data = $request->validated();

        $relevant = $data['relevance'] === '1';
        $recommendationItem->update([
            'relevance' => $relevant,
            'rejection_reason' => $relevant ? null : $data['rejection_reason'],
            'evaluated_at' => now(),
        ]);

        return back()->with('success', 'Thanks. Your response will help evaluate recommendation relevance.');
    }
}
