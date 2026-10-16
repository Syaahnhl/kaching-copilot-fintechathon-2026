<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function buildDuplicateKey($userId, $outletId, string $name, string $category, $price): string
    {
        return implode('|', [
            (int) $userId,
            (int) ($outletId ?? 0),
            strtolower(preg_replace('/\s+/', ' ', trim($name))),
            strtolower(preg_replace('/\s+/', ' ', trim($category))),
            number_format((float) $price, 2, '.', ''),
        ]);
    }

    public function up(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        Schema::table('menus', function (Blueprint $table) {
            if (!Schema::hasColumn('menus', 'duplicate_key')) {
                $table->string('duplicate_key', 255)->nullable()->after('deleted_at');
            }
        });

        DB::transaction(function () {
            $activeMenus = DB::table('menus')
                ->whereNull('deleted_at')
                ->orderBy('id', 'asc')
                ->get();

            $seenKeys = [];

            foreach ($activeMenus as $menu) {
                $duplicateKey = $this->buildDuplicateKey(
                    $menu->user_id,
                    $menu->outlet_id ?? 0,
                    (string) $menu->name,
                    (string) $menu->category,
                    $menu->price
                );

                if (!isset($seenKeys[$duplicateKey])) {
                    $seenKeys[$duplicateKey] = $menu->id;
                    DB::table('menus')
                        ->where('id', $menu->id)
                        ->update(['duplicate_key' => $duplicateKey]);
                    continue;
                }

                $primaryMenuId = $seenKeys[$duplicateKey];

                if (Schema::hasTable('menu_ingredients') && Schema::hasColumn('menu_ingredients', 'menu_id')) {
                    DB::table('menu_ingredients')
                        ->where('menu_id', $menu->id)
                        ->update(['menu_id' => $primaryMenuId]);
                }

                if (Schema::hasTable('menu_modifier') && Schema::hasColumn('menu_modifier', 'menu_id')) {
                    DB::table('menu_modifier')
                        ->where('menu_id', $menu->id)
                        ->update(['menu_id' => $primaryMenuId]);
                }

                DB::table('menus')
                    ->where('id', $menu->id)
                    ->update([
                        'duplicate_key' => null,
                        'is_available' => false,
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->unique('duplicate_key', 'menus_duplicate_key_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        Schema::table('menus', function (Blueprint $table) {
            $table->dropUnique('menus_duplicate_key_unique');
            $table->dropColumn('duplicate_key');
        });
    }
};
