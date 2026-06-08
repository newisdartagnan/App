<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;

class DossierNumberService
{
    public function generate(string $establishmentCode): string
    {
        $year = now()->year;
        $prefix = "{$establishmentCode}-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $last = Patient::where('dossier_number', 'like', "{$prefix}%")
                ->orderByDesc('dossier_number')
                ->lockForUpdate()
                ->value('dossier_number');

            $sequence = 1;
            if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
                $sequence = (int) $matches[1] + 1;
            }

            return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
