<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckAndImportGtfs extends Command
{
    protected $signature = 'gtfs:check-and-import';
    protected $description = 'Importiert GTFS nur bei Änderungen';

    public function handle()
    {
        $url = config('gtfs.static_url');
        $tempPath = storage_path('app/gtfs_check.zip');

        file_put_contents($tempPath, fopen($url, 'r'));

        $hash = hash_file('sha256', $tempPath);
        $oldHash = Cache::get('gtfs_hash');

        if ($oldHash === $hash) {
            $this->info('Keine Änderungen im GTFS Feed.');
            unlink($tempPath);
            return self::SUCCESS;
        }

        $this->info('Neue GTFS-Version erkannt.');

        unlink($tempPath);

        $exitCode = $this->call('gtfs:import', [
            '--fresh' => true,
        ]);

        if ($exitCode === 0) {
            Cache::forever('gtfs_hash', $hash);
        }

        return $exitCode;
    }
}