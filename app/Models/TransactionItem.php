<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionItem extends Model
{
    use HasFactory;

    protected $table = 'transaction_items';

    protected $fillable = [
        'transaction_id',
        'menu_id',
        'menu_name',
        'qty',
        'price',
        'note',
        'status',
        'is_sent_to_kds',
        'kds_status',
        'qty_done',
        'category_snapshot',
        'refund_qty'
    ];

    // [UBAH INI] Pastikan qty di-cast sebagai double
    protected $casts = [
        'qty' => 'double', // <--- FIX: Sekarang Laravel akan mempertahankan angka desimal (0.5)
        'price' => 'double',
        'is_sent_to_kds' => 'boolean',
        'refund_qty' => 'double',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'menu_id', 'id');
    }
}
