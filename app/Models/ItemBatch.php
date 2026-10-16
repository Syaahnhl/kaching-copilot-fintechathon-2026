<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemBatch extends Model
{
    use HasFactory;
    protected $fillable = ['item_id', 'batch_number', 'expired_date', 'current_qty'];
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
