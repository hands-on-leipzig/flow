<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CalendarFeedService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CalendarFeedController extends Controller
{
    public function __construct(private CalendarFeedService $calendar) {}

    public function all(Request $request): Response
    {
        $feed = $this->calendar->feedAll();

        return $this->icsResponse($request, $feed['body'], $feed['lastModified']);
    }

    protected function icsResponse(Request $request, string $body, ?DateTimeInterface $lastModified): Response
    {
        $response = new Response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ]);
        $response->setEtag(sha1($body));
        if ($lastModified !== null) {
            $response->setLastModified(Carbon::parse($lastModified)->utc());
        }
        $response->isNotModified($request);

        return $response;
    }
}
