<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RoomImage;
use App\Models\RoomPlacementEvaluation;
use App\Http\Requests\Admin\RoomPlacementEvaluationRequest;
use Illuminate\Http\Request;

class RoomPlacementEvaluationController extends Controller
{
    private const DIMENSIONS = ['floor_detection_correct', 'zone_appropriate', 'clearance_respected', 'scale_plausible'];

    public function index(Request $request)
    {
        $rooms = RoomImage::whereNotNull('analyzed_at')->latest('analyzed_at')->paginate(12);
        $evaluations = RoomPlacementEvaluation::where('evaluator_id', $request->user()->id)
            ->whereIn('room_image_id', $rooms->pluck('id'))->get()->keyBy('room_image_id');
        $totalEvaluations = RoomPlacementEvaluation::count();
        $geminiEvaluations = RoomPlacementEvaluation::whereHas('roomImage', fn ($query) => $query->where('analysis->gemini_used', true))->count();
        $sourceCounts = [
            'gemini' => $geminiEvaluations,
            'fallback' => $totalEvaluations - $geminiEvaluations,
        ];
        $aggregateQuery = RoomPlacementEvaluation::query();
        foreach (self::DIMENSIONS as $dimension) {
            $aggregateQuery->selectRaw("SUM(CASE WHEN {$dimension} IS NOT NULL THEN 1 ELSE 0 END) as {$dimension}_count")
                ->selectRaw("SUM(CASE WHEN {$dimension} = 1 THEN 1 ELSE 0 END) as {$dimension}_correct");
        }
        $totals = $aggregateQuery->first();
        $metrics = [];
        foreach (self::DIMENSIONS as $dimension) {
            $count = (int) $totals->{"{$dimension}_count"};
            $correct = (int) $totals->{"{$dimension}_correct"};
            $metrics[$dimension] = [
                'count' => $count,
                'correct' => $correct,
                'rate' => $count === 0 ? null : $correct / $count * 100,
            ];
        }

        return view('admin.room-placement-evaluation', compact('rooms', 'evaluations', 'metrics', 'sourceCounts'));
    }

    public function store(RoomPlacementEvaluationRequest $request, RoomImage $roomImage)
    {
        abort_unless($roomImage->analyzed_at, 404);
        $data = $request->validated();
        $assessment = ['room_image_id' => $roomImage->id, 'evaluator_id' => $request->user()->id, 'notes' => $data['notes'] ?? null];
        foreach (self::DIMENSIONS as $dimension) {
            $assessment[$dimension] = $data[$dimension] === 'not_applicable' ? null : $data[$dimension] === 'yes';
        }
        RoomPlacementEvaluation::updateOrCreate(
            ['room_image_id' => $roomImage->id, 'evaluator_id' => $request->user()->id],
            $assessment,
        );

        return back()->with('success', 'Room placement assessment saved.');
    }
}
