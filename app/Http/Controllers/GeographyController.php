<?php

namespace App\Http\Controllers;

use App\Models\Commune;
use App\Models\Region;
use Illuminate\Http\JsonResponse;

class GeographyController extends Controller
{
    public function communes(Region $region): JsonResponse
    {
        return response()->json(
            Commune::query()
                ->where('region_id', $region->id)
                ->orderBy('name')
                ->get(['id', 'region_id', 'code', 'name'])
        );
    }
}
