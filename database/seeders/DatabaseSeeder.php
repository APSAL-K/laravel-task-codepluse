<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Wireless Mouse', 'Ergonomic 2.4 GHz wireless mouse with silent clicks and a 12-month battery.', 79900, 50],
            ['Mechanical Keyboard', 'Hot-swappable mechanical keyboard with tactile switches and white backlight.', 349900, 20],
            ['USB-C Cable', 'Braided 1 m USB-C cable, 100 W fast charging and 480 Mbps data.', 29900, 200],
            ['Laptop Stand', 'Foldable aluminium laptop stand with six height levels.', 129900, 5],
        ] as [$name, $description, $price, $stock]) {
            $product = Product::firstOrNew(['name' => $name]);
            if (! $product->exists) {
                $product->price = $price;
                $product->stock_quantity = $stock;
            }
            $product->description = $description;
            $product->save();
        }
    }
}
