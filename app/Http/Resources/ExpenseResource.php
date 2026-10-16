<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'cabang_id' => $this->cabang_id,
            'name' => $this->name,
            'amount' => $this->amount,
            'qty' => $this->qty,
            'unit_price' => $this->unit_price,
            'category' => $this->category,
            'fund_source' => $this->fund_source,
            'date' => $this->date,
            'note' => $this->note,
            'receipt_image' => $this->receipt_image ? asset('storage/' . $this->receipt_image) : null,
            'supplier' => $this->supplier,
            'is_reimburse' => $this->is_reimburse,
            'is_recurring' => $this->is_recurring,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
