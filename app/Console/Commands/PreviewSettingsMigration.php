<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreviewSettingsMigration extends Command
{
    protected $signature = 'kaching:preview-settings-migration';
    protected $description = 'Melihat preview data mapping untuk migrasi isolasi settings tingkat outlet (Dry Run)';

    public function handle()
    {
        $this->info("==================================================================");
        $this->info("   KACHING SETTINGS OUTLET-LEVEL ISOLATION MIGRATION PREVIEW      ");
        $this->info("==================================================================");

        if (!Schema::hasTable('settings')) {
            $this->error("Tabel 'settings' tidak ditemukan!");
            return 1;
        }
        if (!Schema::hasTable('outlets')) {
            $this->error("Tabel 'outlets' tidak ditemukan!");
            return 1;
        }

        $settings = DB::table('settings')->get();
        $outlets = DB::table('outlets')->get();

        $this->info("Total Settings Rows: " . $settings->count());
        $this->info("Total Outlets: " . $outlets->count());
        $this->info("");

        $headers = ['Setting ID', 'User ID', 'Store Name', 'Current Outlet ID', 'Proposed Outlet ID', 'Mapping Reason'];
        $rows = [];

        $groupACount = 0;
        $groupB1Count = 0;
        $groupB2Count = 0;
        $groupCCount = 0;

        foreach ($settings as $s) {
            $userOutlets = $outlets->where('user_id', $s->user_id);
            $proposedOutletId = 'NULL';
            $reason = 'UNKNOWN';

            if ($userOutlets->count() === 1) {
                $proposedOutletId = $userOutlets->first()->id;
                $reason = 'DETERMINISTIC_SINGLE (Group A)';
                $groupACount++;
            } elseif ($userOutlets->count() > 1) {
                // Group B Multi-outlet
                $matchedOutlet = null;
                foreach ($userOutlets as $o) {
                    if (!empty($o->name) && strtolower($s->store_name) === strtolower($o->name)) {
                        $matchedOutlet = $o;
                        break;
                    }
                }

                if ($matchedOutlet) {
                    $proposedOutletId = $matchedOutlet->id;
                    $reason = 'METADATA_MATCH (Group B1)';
                    $groupB1Count++;
                } else {
                    $proposedOutletId = 'NULL';
                    $reason = 'AMBIGUOUS (Group B2)';
                    $groupB2Count++;
                }
            } else {
                // Group C Orphaned
                $proposedOutletId = 'NULL';
                $reason = 'ORPHANED_NO_OUTLETS (Group C)';
                $groupCCount++;
            }

            // In our migration, settings has no current outlet_id column yet.
            // Check if column exists to fetch current
            $currentOutletId = Schema::hasColumn('settings', 'outlet_id') ? ($s->outlet_id ?? 'NULL') : 'NULL';

            $rows[] = [
                $s->id,
                $s->user_id,
                $s->store_name,
                $currentOutletId,
                $proposedOutletId,
                $reason
            ];
        }

        $this->table($headers, $rows);

        // Group D check
        $outletsWithoutSettings = DB::table('outlets')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('settings')
                    ->whereColumn('settings.user_id', 'outlets.user_id');
            })->get();

        $this->info("");
        $this->info("==================================================================");
        $this->info("Summary Statistics:");
        $this->info("  Group A (Single Outlet -> Auto-assigned): " . $groupACount);
        $this->info("  Group B1 (Multi-outlet -> Metadata Matched): " . $groupB1Count);
        $this->info("  Group B2 (Multi-outlet -> Ambiguous/Review): " . $groupB2Count);
        $this->info("  Group C (Orphaned -> Kept NULL): " . $groupCCount);
        $this->info("  Group D (Outlets without settings -> New Default): " . $outletsWithoutSettings->count());
        $this->info("==================================================================");

        if ($outletsWithoutSettings->count() > 0) {
            $this->info("Outlets requiring default settings (Group D):");
            $dHeaders = ['Outlet ID', 'User ID', 'Outlet Name', 'Proposed Default Settings Action'];
            $dRows = [];
            foreach ($outletsWithoutSettings as $o) {
                $dRows[] = [
                    $o->id,
                    $o->user_id,
                    $o->name,
                    "GENERATE_NEW_DEFAULT (Financial = 0, QRIS = NULL)"
                ];
            }
            $this->table($dHeaders, $dRows);
        }

        return 0;
    }
}
