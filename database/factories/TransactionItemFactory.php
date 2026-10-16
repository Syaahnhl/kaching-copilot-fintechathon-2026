<?php
namespace Database\Factories;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;
class TransactionItemFactory extends Factory {
    protected $model = TransactionItem::class;
    public function definition(): array {
        return ["transaction_id" => 1, "menu_name" => "Test", "qty" => 1, "price" => 10000, "status" => "Selesai"];
    }
}
