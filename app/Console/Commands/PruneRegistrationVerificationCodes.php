<?php

namespace App\Console\Commands;

use App\Models\RegistrationVerificationCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneRegistrationVerificationCodes extends Command
{
    protected $signature = 'registration-codes:prune {--dry-run : Count eligible records without deleting them}';

    protected $description = 'Delete registration verification metadata after its post-use or post-expiry retention period';

    public function handle(): int
    {
        $hours = max(1, (int) config('privacy.registration_verification_retention_hours', 24));
        $cutoff = now()->subHours($hours);
        $eligible = RegistrationVerificationCode::query()->where(function (Builder $query) use ($cutoff): void {
            $query->where('expires_at', '<=', $cutoff)
                ->orWhere('used_at', '<=', $cutoff);
        });

        if ($this->option('dry-run')) {
            $this->info('Eligible registration verification records: '.$eligible->count().'. No records were deleted.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $eligible->select('id')->chunkById(1000, function ($records) use ($eligible, &$deleted): void {
            // Recheck eligibility at deletion time; never delete users or active codes.
            $deleted += (clone $eligible)->whereKey($records->modelKeys())->delete();
        });

        $this->info("Deleted registration verification records: {$deleted}.");

        return self::SUCCESS;
    }
}
