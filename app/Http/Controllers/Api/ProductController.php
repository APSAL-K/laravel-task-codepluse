<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private const COLUMNS = ['id', 'name', 'description', 'image_url', 'price', 'stock_quantity'];

    public function index(Request $request)
    {
        $query = Product::orderBy('id');
        if (! $request->boolean('all')) {
            $query->where('stock_quantity', '>', 0);
        }

        return response()->json(['data' => $query->get(self::COLUMNS)]);
    }

    public function store(Request $request)
    {
        $product = Product::create($this->validated($request));

        return response()->json(['message' => 'Product created.', 'data' => $product], 201);
    }

    public function update(Request $request, Product $product)
    {
        $product->update($this->validated($request));

        return response()->json(['message' => 'Product updated.', 'data' => $product->fresh()]);
    }

    public function destroy(Product $product)
    {
        abort_if(
            $product->orderItems()->exists(),
            409,
            'This product is part of existing orders. Set its stock to 0 instead of deleting it.',
        );

        $product->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url:http,https', 'max:500'],
            'price' => ['required', 'integer', 'min:100', 'max:100000000'],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ], [
            'price.min' => 'Price must be at least ₹1.',
            'image_url.url' => 'Image must be a valid http(s) URL.',
        ]);
    }
}
