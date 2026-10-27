<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Requests\Admin\ProductStatusRequest;
use App\Models\FurnitureProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = FurnitureProduct::query()->withCount('reservations')->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('name', 'like', '%'.$request->q.'%')->orWhere('category', 'like', '%'.$request->q.'%')))->latest()->paginate(15)->withQueryString();
        return view('admin.products.index', compact('products'));
    }

    public function create()
    {
        return view('admin.products.create', $this->materialOptions());
    }

    public function store(ProductRequest $request)
    {
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['created_by'] = $request->user()->id;
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }
        $data['tags'] = $this->tags($data['tags'] ?? '');
        unset($data['image']);
        FurnitureProduct::create($data);

        return redirect()->route('admin.products.index')->with('success', 'Furniture item added to the catalog.');
    }

    public function edit(FurnitureProduct $product)
    {
        return view('admin.products.edit', array_merge(['product' => $product], $this->materialOptions()));
    }

    public function update(ProductRequest $request, FurnitureProduct $product)
    {
        $data = $request->validated();
        if ($product->name !== $data['name']) {
            $data['slug'] = $this->uniqueSlug($data['name'], $product->id);
        }
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }
        $data['tags'] = $this->tags($data['tags'] ?? '');
        unset($data['image']);
        $product->update($data);

        return redirect()->route('admin.products.index')->with('success', 'Furniture item updated.');
    }

    public function updateStatus(ProductStatusRequest $request, FurnitureProduct $product)
    {
        $data = $request->validated();
        $product->update(['status' => $data['status']]);

        return back()->with('success', 'Furniture status updated to '.$data['status'].'.');
    }

    public function destroy(FurnitureProduct $product)
    {
        $product->update(['status' => 'inactive']);

        return back()->with('success', 'Furniture deactivated. It is hidden from customer browsing and its history is preserved.');
    }

    public function destroyPermanently(FurnitureProduct $product)
    {
        if ($product->reservations()->exists()) {
            return back()->with('warning', 'This item has reservation history and cannot be permanently deleted. Deactivate it to keep those records.');
        }

        $imagePath = $product->image_path;
        $product->delete();

        if ($imagePath && ! str_starts_with($imagePath, 'catalog:') && ! str_starts_with($imagePath, 'demo:')) {
            Storage::disk('public')->delete($imagePath);
        }

        return back()->with('success', 'Furniture permanently deleted along with its catalog activity and preview records.');
    }

    private function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;
        while (FurnitureProduct::where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function materialOptions(): array
    {
        return [
            'woodTypes' => FurnitureProduct::query()->distinct()->orderBy('wood_type')->pluck('wood_type'),
            'varnishFinishes' => FurnitureProduct::query()->distinct()->orderBy('varnish_finish')->pluck('varnish_finish'),
        ];
    }
    private function tags(string $tags): array
    {
        return collect(explode(',', $tags))->map(fn ($tag) => trim($tag))->filter()->unique()->values()->all();
    }
}
