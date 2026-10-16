<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'cashier_name' => $this->cashier_name,
            'start_cash' => (double) $this->start_cash,
            'end_cash' => $this->end_cash ? (double) $this->end_cash : null,
            'cash_sales' => $this->cash_sales ? (double) $this->cash_sales : null,
            'non_cash_sales' => $this->non_cash_sales ? (double) $this->non_cash_sales : null,
            'total_sales' => $this->total_sales ? (double) $this->total_sales : null,
            'cash_drop' => $this->cash_drop ? (double) $this->cash_drop : null,
            'expected_cash' => $this->expected_cash ? (double) $this->expected_cash : null,
            'status' => $this->status,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'cashier_type' => $this->cashier_type,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
