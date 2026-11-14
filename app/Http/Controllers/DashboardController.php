<?php

namespace App\Http\Controllers;

use App\Models\FurnitureProduct;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()?->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }
        $topProducts = FurnitureProduct::active()->orderByActivity()->limit(3)->get();

        return view('home', compact('topProducts'));
    }
}
