<?php

namespace App\Console\Commands;

use App\Models\Capture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeCaptureImages extends Command
{
    protected $signature = 'captures:purge-images';

    protected $description = 'Delete stored badge and business-card images after the configured retention window.';

    public function handle(): int
    {
        $retentionDays = (int) config('services.capture_retention_days', 30);
        $cutoff = now()->subDays($retentionDays);
        $purged = 0;

        Capture::query()
            ->whereNotNull('image_path')
            ->whereNull('image_purged_at')
            ->where('created_at', '<=', $cutoff)
            ->each(function (Capture $capture) use (&$purged): void {
                Storage::disk('local')->delete($capture->image_path);
                $capture->forceFill([
                    'image_path' => null,
                    'image_purged_at' => now(),
                ])->save();
                $purged++;
            });

        $this->info("Purged {$purged} capture image(s).");

        return self::SUCCESS;
    }
}
