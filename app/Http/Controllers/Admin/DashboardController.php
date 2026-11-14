<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AiUsageOverview;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'customers' => User::where('role', 'customer')->count(),
            'products' => FurnitureProduct::count(),
            'pending_reservations' => Reservation::where('status', 'pending')->count(),
            'recommendations' => Recommendation::count(),
        ];
        $recentReservations = Reservation::with('user', 'product')->latest()->orderByDesc('id')->paginate(8);
        $topProducts = FurnitureProduct::orderByActivity()->limit(5)->get();
        return view('admin.dashboard', compact('stats', 'recentReservations', 'topProducts'));
    }

    public function apiUsage(AiUsageOverview $overview)
    {
        return view('admin.api-usage', ['aiUsage' => $overview->snapshot()]);
    }
}
