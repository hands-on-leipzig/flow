<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SlideShow;


class CarouselController extends Controller
{

    public function getSlideshowsForEvent($eventId)
    {
        $slideshows = SlideShow::where('event', $eventId)
            ->with('slides')
            ->findOrFail($eventId);

        return response()->json($slideshows);
    }
}
