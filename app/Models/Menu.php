<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Menu extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'menus';

    // Kolom yang boleh diisi (termasuk user_id)
    protected $fillable = [
        'user_id',
        'outlet_id',
        'name',
        'category',
        'price',
        'cost_price',
        'stock',
        'has_variant',
        'description',
        'image_url',
        'is_available',
        'label',        // <-- Pastikan ini ada
        'label_title',
        'unit',
        'is_kds',
        'price_gofood',
        'price_grabfood',
        'price_shopeefood',
        'gramasi',
        'kalori',
        'allergen_tags',
        'duplicate_key',
        'variant_config',
        'version',
    ];

    protected $casts = [
        'price' => 'double',
        'cost_price' => 'double',
        'stock' => 'double',
        'version' => 'integer',
        'has_variant' => 'boolean',
        'is_available' => 'boolean',
        'is_kds' => 'boolean',
        'price_gofood' => 'integer',
        'price_grabfood' => 'integer',
        'price_shopeefood' => 'integer',
        'allergen_tags' => 'array',
        'variant_config' => 'string',
    ];

    public function ingredients()
    {
        return $this->hasMany(MenuIngredient::class);
    }
}
