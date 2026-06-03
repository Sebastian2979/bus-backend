<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShapeController extends Controller
{
    public function show(string $shapeId)
    {
        $shapes = DB::table('shapes')
            ->where('shape_id', $shapeId)
            ->orderBy('shape_pt_sequence')
            ->get();

        return $shapes->map(fn($shape) => [
            'latitude' => (float) $shape->shape_pt_lat,
            'longitude' => (float) $shape->shape_pt_lon,
        ]);
    }
}
