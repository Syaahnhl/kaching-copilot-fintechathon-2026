<?php
namespace Database\Factories;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
class TransactionFactory extends Factory {
    protected $model = Transaction::class;
    public function definition(): array {
        return [
            "user_id" => 1,
            "outlet_id" => 1,
            "app_uuid" => fake()->uuid(),
            "total_amount" => 10000,
            "pay_amount" => 10000,
            "status" => "Selesai",
            "payment_method" => "Tunai",
            "created_at_device" => \Carbon\Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s'),
        ];
    }
}
