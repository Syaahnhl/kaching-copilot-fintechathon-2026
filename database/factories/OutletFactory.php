<?php
namespace Database\Factories;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;
class OutletFactory extends Factory {
    protected $model = Outlet::class;
    public function definition(): array {
        return ["name" => "Test Outlet", "user_id" => 1];
    }
}
