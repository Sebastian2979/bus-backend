<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckAndImportGtfs extends Command
{
    protected $signature = 'gtfs:check-and-import';
    protected $description = 'Importiert GTFS nur bei Änderungen';

    public function handle()
    {
        $startTime = now();

        Log::info('GTFS check started', [
            'time' => $startTime->toDateTimeString(),
        ]);

        try {
            $url = config('gtfs.static_url');
            $tempPath = storage_path('app/gtfs_check.zip');

            Log::info('GTFS download started', [
                'url' => $url
            ]);

            file_put_contents($tempPath, fopen($url, 'r'));

            $sizeMb = file_exists($tempPath)
                ? round(filesize($tempPath) / 1024 / 1024, 1)
                : 0;

            Log::info('GTFS downloaded', [
                'size_mb' => $sizeMb
            ]);

            $hash = hash_file('sha256', $tempPath);
            $oldHash = Cache::get('gtfs_hash');

            Log::info('GTFS hash calculated', [
                'new_hash' => $hash,
                'old_hash' => $oldHash
            ]);

            if ($oldHash === $hash) {
                Log::info('GTFS unchanged - skipping import');

                $this->info('Keine Änderungen im GTFS Feed.');
                unlink($tempPath);

                return self::SUCCESS;
            }

            Log::info('GTFS change detected - import triggered');

            $this->info('Neue GTFS-Version erkannt.');

            unlink($tempPath);

            Log::info('GTFS import started');

            $exitCode = $this->call('gtfs:import', [
                '--fresh' => true,
            ]);

            if ($exitCode === 0) {
                Cache::forever('gtfs_hash', $hash);

                Log::info('GTFS import finished successfully', [
                    'duration_seconds' => $startTime->diffInSeconds(now())
                ]);
            } else {
                Log::warning('GTFS import finished with errors', [
                    'exit_code' => $exitCode
                ]);
            }

            return $exitCode;

        } catch (\Throwable $e) {

            Log::error('GTFS check/import failed', [
                'message' => $e->getMessage(),
            ]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}