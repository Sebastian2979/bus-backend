<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class RouteController extends Controller
{
    public function index()
    {
        return DB::table('routes')
            ->join('agencies', 'routes.agency_id', '=', 'agencies.agency_id')
            ->where('agencies.agency_name', 'like', 'Berliner Verkehrsbetriebe')
            ->whereIn('routes.route_type', [3, 700, 106, 109, 900])
            ->select(
                'routes.route_id',
                'routes.route_short_name',
                'routes.route_long_name',
                'routes.route_type',
                'routes.route_color'
            )
            ->orderBy('routes.route_short_name')
            ->get();
    }
}
