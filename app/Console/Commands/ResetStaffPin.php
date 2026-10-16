<?php

namespace App\Console\Commands;

use App\Models\Staff;
use Illuminate\Console\Command;

class ResetStaffPin extends Command
{
    protected $signature = 'staff:reset-pin {staff_id} {pin}';

    protected $description = 'Reset PIN kasir/staf (plain text, 4-6 digit)';

    public function handle(): int
    {
        $staff = Staff::find($this->argument('staff_id'));

        if (!$staff) {
            $this->error('Staff tidak ditemukan.');
            return self::FAILURE;
        }

        $pin = Staff::normalizePin((string) $this->argument('pin'));

        if (strlen($pin) < 4 || strlen($pin) > 6) {
            $this->error('PIN harus 4-6 digit angka.');
            return self::FAILURE;
        }

        $staff->pin = $pin;
        $staff->save();

        $this->info("PIN untuk {$staff->staff_name} (ID {$staff->id}) diset ke: {$pin}");

        return self::SUCCESS;
    }
}
