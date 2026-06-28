<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TripController extends Controller
{
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

    public function getUpcomingTripDepartures($tripId)
    {
        $now = now('Europe/Berlin')->format('H:i:s');
        $today = Carbon::today('Europe/Berlin');
        $todayDate = $today->format('Ymd');
        $weekday = strtolower($today->format('l'));

        Log::info('Zeitinfo', [
            'now' => $now,
            'todayDate' => $todayDate,
            'weekday' => $weekday,
        ]);

        /*
    |--------------------------------------------------------------------------
    | 1. Ausgewählten Trip laden
    |--------------------------------------------------------------------------
    */
        $selectedTrip = DB::table('trips')
            ->where('trip_id', $tripId)
            ->first();

        if (!$selectedTrip) {
            return response()->json([
                'error' => 'Trip nicht gefunden'
            ], 404);
        }

        /*
    |--------------------------------------------------------------------------
    | 2. Start- und Endhaltestelle dieses Trips bestimmen
    |--------------------------------------------------------------------------
    */
        $selectedStops = DB::table('stop_times')
            ->where('trip_id', $tripId)
            ->orderBy('stop_sequence')
            ->get();

        if ($selectedStops->isEmpty()) {
            return response()->json([
                'error' => 'Keine stop_times für Trip'
            ], 404);
        }

        $startStopId = $selectedStops->first()->stop_id;
        $endStopId = $selectedStops->last()->stop_id;

        Log::info('Selected Trip Stops', [
            'startStopId' => $startStopId,
            'endStopId' => $endStopId,
        ]);

        /*
    |--------------------------------------------------------------------------
    | 3. Gültige service_ids für heute bestimmen
    |--------------------------------------------------------------------------
    */
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

        /*
    |--------------------------------------------------------------------------
    | 4. Alle heutigen Trips derselben Route + Richtung
    |--------------------------------------------------------------------------
    */
        $candidateTrips = DB::table('trips')
            ->where('route_id', $selectedTrip->route_id)
            ->where('direction_id', $selectedTrip->direction_id)
            ->whereIn('service_id', $serviceIds)
            ->pluck('trip_id');

        Log::info('Candidate Trips Count', [
            'count' => $candidateTrips->count()
        ]);

        /*
    |--------------------------------------------------------------------------
    | 5. Nur Trips mit identischem Start und Ende behalten
    |--------------------------------------------------------------------------
    */
        $matchingTrips = collect();

        foreach ($candidateTrips as $candidateTripId) {

            $tripStops = DB::table('stop_times')
                ->where('trip_id', $candidateTripId)
                ->orderBy('stop_sequence')
                ->get();

            if ($tripStops->isEmpty()) {
                continue;
            }

            $tripStart = $tripStops->first()->stop_id;
            $tripEnd = $tripStops->last()->stop_id;

            if (
                $tripStart === $startStopId &&
                $tripEnd === $endStopId
            ) {
                $matchingTrips->push($candidateTripId);
            }
        }

        Log::info('Matching Trips', [
            'count' => $matchingTrips->count()
        ]);

        /*
    |--------------------------------------------------------------------------
    | 6. Abfahrten an der Starthaltestelle holen
    |--------------------------------------------------------------------------
    */
        $departures = DB::table('stop_times')
            ->whereIn('trip_id', $matchingTrips)
            ->where('stop_id', $startStopId)
            ->select('trip_id', 'departure_time')
            ->orderBy('departure_time')
            ->get();

        /*
    |--------------------------------------------------------------------------
    | 7. Nur zukünftige Abfahrten
    |--------------------------------------------------------------------------
    */
        $nextDepartures = $departures
            ->filter(function ($departure) use ($now) {
                return $departure->departure_time >= $now;
            })
            ->take(5)
            ->values();

        return response()->json([
            'selected_trip_id' => $tripId,
            'start_stop_id' => $startStopId,
            'end_stop_id' => $endStopId,
            'current_time' => $now,
            'next_departures' => $nextDepartures
        ]);
    }
}
