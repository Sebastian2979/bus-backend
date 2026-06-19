<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TripController extends Controller
{
    private function gtfsTimeToSeconds($time)
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, 0);

        return ((int)$h * 3600) + ((int)$m * 60) + (int)$s;
    }

    public function byLine($line)
    {
        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')

            // START HALTESTELLE (stop_sequence = 0)
            ->join('stop_times as st_start', function ($join) {
                $join->on('trips.trip_id', '=', 'st_start.trip_id')
                    ->where('st_start.stop_sequence', '=', 0);
            })

            // END HALTESTELLE (max stop_sequence pro trip)
            ->join('stop_times as st_end', function ($join) {
                $join->on('trips.trip_id', '=', 'st_end.trip_id')
                    ->whereRaw('st_end.stop_sequence = (
                     SELECT MAX(st2.stop_sequence)
                     FROM stop_times st2
                     WHERE st2.trip_id = trips.trip_id
                 )');
            })

            // STOP DETAILS
            ->join('stops as s_start', 'st_start.stop_id', '=', 's_start.stop_id')
            ->join('stops as s_end', 'st_end.stop_id', '=', 's_end.stop_id')

            ->where('routes.route_short_name', $line)

            ->select(
                'trips.trip_id',
                'trips.shape_id',
                'trips.direction_id',
                'trips.trip_headsign',

                // 🚍 neu: echte Richtung
                's_start.stop_name as start_name',
                's_end.stop_name as end_name'
            )

            ->get()

            // optional: Duplikate sauber reduzieren
            ->unique(function ($item) {
                return $item->start_name . '-' . $item->end_name;
            })
            ->values();

        return response()->json($trips);
    }

    // Optional: gruppiert für UI
    public function grouped()
    {
        return DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->select(
                'routes.route_short_name',
                'trips.trip_id',
                'trips.shape_id',
                'trips.direction_id',
                'trips.trip_headsign'
            )
            ->get()
            ->groupBy('route_short_name');
    }

    public function stopTimes($tripId)
    {
        Log::info("TripId", ['tripId' => $tripId]);
        return DB::table('stop_times')
            ->where('stop_times.trip_id', $tripId)
            ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
            ->orderBy('stop_times.stop_sequence')
            ->select(
                'stop_times.stop_sequence',
                'stop_times.arrival_time',
                'stop_times.departure_time',
                'stops.stop_name',
                'stops.stop_lat',
                'stops.stop_lon'
            )
            ->get();
    }

    public function upcomingDepartures($tripId)
    {
        $now = now();

        $nowSeconds = ($now->hour * 3600) + ($now->minute * 60) + $now->second;

        return DB::table('stop_times')
            ->where('trip_id', $tripId)
            ->where('stop_sequence', 0)
            ->select('trip_id', 'departure_time')
            ->orderBy('departure_time')
            ->get()
            ->filter(function ($row) use ($nowSeconds) {

                [$h, $m, $s] = array_pad(explode(':', $row->departure_time), 3, 0);
                $sec = ($h * 3600) + ($m * 60) + $s;

                return $sec >= $nowSeconds || $sec >= 86400;
            })
            ->take(5)
            ->values();
    }

    public function directions($line)
    {
        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->where('routes.route_short_name', $line)
            ->select(
                'trips.trip_id',
                'trips.shape_id',
                'trips.direction_id',
                'trips.trip_headsign'
            )
            ->get();

        $result = $trips->map(function ($trip) use ($line) {
            $firstStop = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('stop_times.trip_id', $trip->trip_id)
                ->orderBy('stop_sequence')
                ->select('stops.stop_name')
                ->first();

            $lastStop = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('stop_times.trip_id', $trip->trip_id)
                ->orderByDesc('stop_sequence')
                ->select('stops.stop_name')
                ->first();

            $startName = $firstStop?->stop_name ?? 'Unknown';
            $endName = $lastStop?->stop_name ?? 'Unknown';

            $directionKey = md5(
                $line . '|' . $startName . '|' . $endName
            );

            return [
                'direction_key' => $directionKey,
                'trip_id' => $trip->trip_id,
                'shape_id' => $trip->shape_id,
                'direction_id' => $trip->direction_id,
                'trip_headsign' => $trip->trip_headsign,
                'start_name' => $startName,
                'end_name' => $endName,
            ];
        });

        return $result
            ->unique('direction_key')
            ->values();
    }

    public function departuresByDirection($line, $start, $end)
    {
        $now = now();

        $nowSeconds = ($now->hour * 3600)
            + ($now->minute * 60)
            + $now->second;

        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->where('routes.route_short_name', $line)
            ->select('trips.trip_id')
            ->get();

        $departures = collect();

        foreach ($trips as $trip) {

            $firstStop = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('stop_times.trip_id', $trip->trip_id)
                ->orderBy('stop_times.stop_sequence')
                ->select(
                    'stop_times.departure_time',
                    'stops.stop_name'
                )
                ->first();

            $lastStop = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('stop_times.trip_id', $trip->trip_id)
                ->orderByDesc('stop_times.stop_sequence')
                ->select(
                    'stop_times.departure_time',
                    'stops.stop_name'
                )
                ->first();

            if (!$firstStop || !$lastStop) {
                continue;
            }

            if ($firstStop->stop_name !== $start || $lastStop->stop_name !== $end) {
                continue;
            }

            [$h, $m, $s] = array_pad(explode(':', $firstStop->departure_time), 3, 0);
            $seconds = ($h * 3600) + ($m * 60) + $s;

            // Mit GTFS "über Mitternacht"-Fix
            if ($seconds < $nowSeconds) {
                continue;
            }

            $departures->push([
                'trip_id' => $trip->trip_id,
                'departure_time' => $firstStop->departure_time,
                'start' => $firstStop->stop_name,
                'end' => $lastStop->stop_name,
                'seconds' => $seconds,
            ]);
        }

        return response()->json(
            $departures
                ->sortBy('seconds')
                ->take(5)
                ->values()
        );
    }

    public function getValidTripsByLineAndStops($line, $start, $end)
    {
        $today = Carbon::today();
        $todayDate = $today->format('Ymd');
        $weekday = strtolower($today->format('l'));

        // 1. service_ids
        $validServiceIds = DB::table('calendars')
            ->where('start_date', '<=', $todayDate)
            ->where('end_date', '>=', $todayDate)
            ->where($weekday, 1)
            ->pluck('service_id');

        $addedServiceIds = DB::table('calendar_dates')
            ->where('date', $todayDate)
            ->where('exception_type', 1)
            ->pluck('service_id');

        $removedServiceIds = DB::table('calendar_dates')
            ->where('date', $todayDate)
            ->where('exception_type', 2)
            ->pluck('service_id');

        $serviceIds = $validServiceIds
            ->merge($addedServiceIds)
            ->diff($removedServiceIds)
            ->unique()
            ->values();

        // 2. Trips
        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->where('routes.route_short_name', $line)
            ->whereIn('trips.service_id', $serviceIds)
            ->select('trips.trip_id')
            ->get();

        $result = collect();

        foreach ($trips as $trip) {

            $stops = DB::table('stop_times')
                ->where('trip_id', $trip->trip_id)
                ->orderBy('stop_sequence')
                ->get();

            if ($stops->isEmpty()) continue;

            // STOP IDs statt Namen
            $startIndex = $stops->search(fn($s) => $s->stop_id === $start);
            $endIndex   = $stops->search(fn($s) => $s->stop_id === $end);

            if ($startIndex === false || $endIndex === false) continue;
            if ($startIndex >= $endIndex) continue;

            $startStop = $stops[$startIndex];

            // GTFS Zeit korrekt parsen (inkl. >24h Fix)
            $seconds = $this->gtfsTimeToSeconds($startStop->departure_time);

            $result->push([
                'trip_id' => $trip->trip_id,
                'departure_time' => $startStop->departure_time,
                'seconds' => $seconds,
                'start' => $start,
                'end' => $end,
            ]);
        }

        return response()->json(
            $result->sortBy('seconds')->values()
        );
    }
}
