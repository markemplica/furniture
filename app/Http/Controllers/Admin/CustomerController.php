<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\Admin\CustomerActivationRequest;
use App\Http\Requests\Admin\CustomerSearchRequest;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(CustomerSearchRequest $request)
    {
        $request->validated();
        $customers = User::where('role', 'customer')->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', '%'.$request->q.'%')->orWhere('email', 'like', '%'.$request->q.'%')))->withCount(['reservations', 'recommendations'])->latest()->paginate(15)->withQueryString();

        return view('admin.customers.index', compact('customers'));
    }

    public function update(CustomerActivationRequest $request, User $user)
    {
        abort_unless($user->role === 'customer', 422);
        $data = $request->validated();
        $user->update($data);

        return back()->with('success', $user->is_active ? 'Customer account activated.' : 'Customer account deactivated.');
    }
}
