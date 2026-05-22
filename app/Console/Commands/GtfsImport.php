<?php
 
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use ZipArchive;
 
class GtfsImport extends Command
{
    protected $signature = 'gtfs:import
                            {--url= : URL zur GTFS-ZIP-Datei (überschreibt GTFS_STATIC_URL in .env)}
                            {--file= : Pfad zu einer lokalen GTFS-ZIP-Datei}
                            {--chunk=500 : Datensätze pro DB-Insert-Batch}
                            {--skip-shapes : shapes.txt überspringen (spart Zeit/Speicher)}
                            {--fresh : Alle GTFS-Tabellen vor dem Import leeren}';
 
    protected $description = 'Importiert GTFS-Staticdaten (ZIP) in die Datenbank';
 
    /** Reihenfolge ist wichtig wegen Foreign Keys */
    private const IMPORT_ORDER = [
        'agency'         => 'agencies',
        'stops'          => 'stops',
        'routes'         => 'routes',
        'calendar'       => 'calendars',
        'calendar_dates' => 'calendar_dates',
        'shapes'         => 'shapes',
        'trips'          => 'trips',
        'stop_times'     => 'stop_times',
    ];
 
    /** Spalten die beim upsert als Unique-Key dienen */
    private const UNIQUE_KEYS = [
        'agencies'       => ['agency_id'],
        'stops'          => ['stop_id'],
        'routes'         => ['route_id'],
        'calendars'      => ['service_id'],
        'calendar_dates' => ['service_id', 'date'],
        'shapes'         => ['shape_id', 'shape_pt_sequence'],
        'trips'          => ['trip_id'],
        'stop_times'     => ['trip_id', 'stop_sequence'],
    ];
 
    private string $extractDir;
 
    public function handle(): int
    {
        $this->extractDir = storage_path('app/gtfs_import_' . now()->format('YmdHis'));
 
        try {
            $zipPath = $this->resolveZipPath();
 
            if (!$zipPath) {
                $this->error('Keine Quelle angegeben. Nutze --url, --file oder setze GTFS_STATIC_URL in der .env');
                return self::FAILURE;
            }
 
            $this->info('📦 Entpacke ZIP...');
            $this->extractZip($zipPath);
 
            if ($this->option('fresh')) {
                $this->truncateTables();
            }
 
            $chunkSize = (int) $this->option('chunk');
            $startTime = now();
 
            foreach (self::IMPORT_ORDER as $filename => $table) {
                if ($filename === 'shapes' && $this->option('skip-shapes')) {
                    $this->line("  ⏭  shapes.txt übersprungen (--skip-shapes)");
                    continue;
                }
 
                $filePath = $this->extractDir . '/' . $filename . '.txt';
 
                if (!file_exists($filePath)) {
                    $this->warn("  ⚠️  {$filename}.txt nicht gefunden, übersprungen.");
                    continue;
                }
 
                $this->importFile($filePath, $table, $filename, $chunkSize);
            }
 
            $elapsed = $startTime->diffForHumans(now(), true);
            $this->newLine();
            $this->info("✅ Import abgeschlossen in {$elapsed}.");
 
            return self::SUCCESS;
 
        } catch (\Throwable $e) {
            $this->error('Import fehlgeschlagen: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
 
        } finally {
            $this->cleanup();
        }
    }
 
    // -------------------------------------------------------
    // ZIP beschaffen
    // -------------------------------------------------------
 
    private function resolveZipPath(): ?string
    {
        // 1. Lokale Datei via --file
        if ($localFile = $this->option('file')) {
            if (!file_exists($localFile)) {
                throw new \RuntimeException("Datei nicht gefunden: {$localFile}");
            }
            return $localFile;
        }
 
        // 2. URL via --url oder .env
        $url = $this->option('url') ?: config('gtfs.static_url');
 
        if (!$url) {
            return null;
        }
 
        return $this->downloadZip($url);
    }
 
    private function downloadZip(string $url): string
    {
        $this->info("⬇️  Lade GTFS von {$url}");
 
        $tempPath = storage_path('app/gtfs_download.zip');
 
        // Streaming-Download für große Dateien
        $response = Http::withOptions(['sink' => $tempPath, 'timeout' => 120])->get($url);
 
        if (!$response->successful()) {
            throw new \RuntimeException("Download fehlgeschlagen: HTTP {$response->status()}");
        }
 
        $sizeMb = round(filesize($tempPath) / 1024 / 1024, 1);
        $this->line("   → {$sizeMb} MB heruntergeladen");
 
        return $tempPath;
    }
 
    // -------------------------------------------------------
    // ZIP entpacken
    // -------------------------------------------------------
 
    private function extractZip(string $zipPath): void
    {
        mkdir($this->extractDir, 0755, true);
 
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
 
        if ($result !== true) {
            throw new \RuntimeException("ZIP konnte nicht geöffnet werden (Code: {$result})");
        }
 
        $zip->extractTo($this->extractDir);
        $zip->close();
 
        $this->line("   → Entpackt nach: {$this->extractDir}");
    }
 
    // -------------------------------------------------------
    // Tabellen leeren (--fresh)
    // -------------------------------------------------------
 
    private function truncateTables(): void
    {
        $this->warn('🗑  Leere alle GTFS-Tabellen...');
 
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(array_values(self::IMPORT_ORDER)) as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
 
    // -------------------------------------------------------
    // Einzelne Datei importieren
    // -------------------------------------------------------
 
    private function importFile(string $filePath, string $table, string $filename, int $chunkSize): void
    {
        $this->line("  📄 Importiere {$filename}.txt → {$table}");
 
        $total    = 0;
        $inserted = 0;
 
        // LazyCollection: liest CSV zeilenweise, ohne alles in den RAM zu laden
        $rows = $this->readCsv($filePath);
 
        $bar = null;
 
        $rows->chunk($chunkSize)->each(function ($chunk) use ($table, $filename, $chunkSize, &$total, &$inserted, &$bar) {
            $records = $chunk->map(fn($row) => $this->transformRow($row, $filename))->filter()->values()->toArray();
 
            if (empty($records)) {
                return;
            }
 
            // Lazy-Init Progress Bar nach dem ersten Chunk (dann kennen wir die Columns)
            if ($bar === null) {
                $bar = $this->output->createProgressBar();
                $bar->setFormat(' %current% Datensätze [%bar%] %elapsed%');
                $bar->start();
            }
 
            $uniqueKeys = self::UNIQUE_KEYS[$table];
            $updateKeys = array_diff(array_keys($records[0]), $uniqueKeys);
 
            // upsert statt insert: idempotent, kann mehrfach laufen
            DB::table($table)->upsert($records, $uniqueKeys, $updateKeys);
 
            $total    += count($records);
            $inserted += count($records);
 
            $bar?->advance(count($records));
        });
 
        $bar?->finish();
        $this->newLine();
        $this->line("     → {$total} Datensätze verarbeitet");
    }
 
    // -------------------------------------------------------
    // CSV-Zeilen als LazyCollection lesen
    // -------------------------------------------------------
 
    private function readCsv(string $filePath): LazyCollection
    {
        return LazyCollection::make(function () use ($filePath) {
            $handle = fopen($filePath, 'r');
 
            if ($handle === false) {
                throw new \RuntimeException("Datei kann nicht gelesen werden: {$filePath}");
            }
 
            // BOM entfernen falls vorhanden (manche Verbünde liefern UTF-8 BOM)
            $firstLine = fgets($handle);
            $firstLine = ltrim($firstLine, "\xEF\xBB\xBF");
            rewind($handle);
            fgets($handle); // Header überspringen (wird separat verarbeitet)
 
            $headers = str_getcsv(trim($firstLine));
            $headers = array_map('trim', $headers);
 
            while (($line = fgets($handle)) !== false) {
                $values = str_getcsv(trim($line));
                if (count($values) === count($headers)) {
                    yield array_combine($headers, $values);
                }
            }
 
            fclose($handle);
        });
    }
 
    // -------------------------------------------------------
    // Zeile transformieren: GTFS-Spaltennamen → DB-Spalten
    // Leere Strings → null, Timestamps hinzufügen
    // -------------------------------------------------------
 
    private function transformRow(array $row, string $filename): ?array
    {
        // Leere Strings in null umwandeln
        $row = array_map(fn($v) => $v === '' ? null : $v, $row);
 
        $now = now()->toDateTimeString();
 
        return match ($filename) {
            'agency' => [
                'agency_id'       => $row['agency_id'] ?? 'default',
                'agency_name'     => $row['agency_name'] ?? null,
                'agency_url'      => $row['agency_url'] ?? null,
                'agency_timezone' => $row['agency_timezone'] ?? null,
                'agency_lang'     => $row['agency_lang'] ?? null,
                'agency_phone'    => $row['agency_phone'] ?? null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
 
            'stops' => [
                'stop_id'        => $row['stop_id'],
                'stop_code'      => $row['stop_code'] ?? null,
                'stop_name'      => $row['stop_name'] ?? null,
                'stop_desc'      => $row['stop_desc'] ?? null,
                'stop_lat'       => isset($row['stop_lat']) ? (float) $row['stop_lat'] : null,
                'stop_lon'       => isset($row['stop_lon']) ? (float) $row['stop_lon'] : null,
                'zone_id'        => $row['zone_id'] ?? null,
                'stop_url'       => $row['stop_url'] ?? null,
                'location_type'  => isset($row['location_type']) ? (int) $row['location_type'] : 0,
                'parent_station' => $row['parent_station'] ?? null,
                'platform_code'  => $row['platform_code'] ?? null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
 
            'routes' => [
                'route_id'         => $row['route_id'],
                'agency_id'        => $row['agency_id'] ?? null,
                'route_short_name' => $row['route_short_name'] ?? null,
                'route_long_name'  => $row['route_long_name'] ?? null,
                'route_desc'       => $row['route_desc'] ?? null,
                'route_type'       => isset($row['route_type']) ? (int) $row['route_type'] : 3,
                'route_url'        => $row['route_url'] ?? null,
                'route_color'      => $row['route_color'] ?? null,
                'route_text_color' => $row['route_text_color'] ?? null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
 
            'calendar' => [
                'service_id' => $row['service_id'],
                'monday'     => (int) ($row['monday'] ?? 0),
                'tuesday'    => (int) ($row['tuesday'] ?? 0),
                'wednesday'  => (int) ($row['wednesday'] ?? 0),
                'thursday'   => (int) ($row['thursday'] ?? 0),
                'friday'     => (int) ($row['friday'] ?? 0),
                'saturday'   => (int) ($row['saturday'] ?? 0),
                'sunday'     => (int) ($row['sunday'] ?? 0),
                'start_date' => $row['start_date'] ?? null,
                'end_date'   => $row['end_date'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
 
            'calendar_dates' => [
                'service_id'     => $row['service_id'],
                'date'           => $row['date'] ?? null,
                'exception_type' => isset($row['exception_type']) ? (int) $row['exception_type'] : null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
 
            'shapes' => [
                'shape_id'            => $row['shape_id'],
                'shape_pt_lat'        => isset($row['shape_pt_lat']) ? (float) $row['shape_pt_lat'] : null,
                'shape_pt_lon'        => isset($row['shape_pt_lon']) ? (float) $row['shape_pt_lon'] : null,
                'shape_pt_sequence'   => isset($row['shape_pt_sequence']) ? (int) $row['shape_pt_sequence'] : null,
                'shape_dist_traveled' => isset($row['shape_dist_traveled']) ? (float) $row['shape_dist_traveled'] : null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ],
 
            'trips' => [
                'trip_id'               => $row['trip_id'],
                'route_id'              => $row['route_id'],
                'service_id'            => $row['service_id'],
                'trip_headsign'         => $row['trip_headsign'] ?? null,
                'trip_short_name'       => $row['trip_short_name'] ?? null,
                'direction_id'          => isset($row['direction_id']) ? (int) $row['direction_id'] : null,
                'block_id'              => $row['block_id'] ?? null,
                'shape_id'              => $row['shape_id'] ?? null,
                'wheelchair_accessible' => isset($row['wheelchair_accessible']) ? (int) $row['wheelchair_accessible'] : null,
                'bikes_allowed'         => isset($row['bikes_allowed']) ? (int) $row['bikes_allowed'] : null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
 
            'stop_times' => [
                'trip_id'             => $row['trip_id'],
                'arrival_time'        => $row['arrival_time'] ?? null,
                'departure_time'      => $row['departure_time'] ?? null,
                'stop_id'             => $row['stop_id'],
                'stop_sequence'       => isset($row['stop_sequence']) ? (int) $row['stop_sequence'] : null,
                'stop_headsign'       => $row['stop_headsign'] ?? null,
                'pickup_type'         => isset($row['pickup_type']) ? (int) $row['pickup_type'] : 0,
                'drop_off_type'       => isset($row['drop_off_type']) ? (int) $row['drop_off_type'] : 0,
                'shape_dist_traveled' => isset($row['shape_dist_traveled']) ? (float) $row['shape_dist_traveled'] : null,
                'timepoint'           => isset($row['timepoint']) ? (int) $row['timepoint'] : null,
                // Kein created_at/updated_at — timestamps() ist in stop_times deaktiviert
            ],
 
            default => null,
        };
    }
 
    // -------------------------------------------------------
    // Temporäre Dateien aufräumen
    // -------------------------------------------------------
 
    private function cleanup(): void
    {
        if (is_dir($this->extractDir)) {
            array_map('unlink', glob($this->extractDir . '/*'));
            rmdir($this->extractDir);
        }
 
        $downloadedZip = storage_path('app/gtfs_download.zip');
        if (file_exists($downloadedZip)) {
            unlink($downloadedZip);
        }
    }
}