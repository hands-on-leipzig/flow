<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CalendarFeedService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
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

    public function postfix(Request $request, string $postfix): Response
    {
        $feed = $this->calendar->feedByPostfix($postfix);
        if ($feed === null) {
            return new Response('', 404);
        }

        return $this->icsResponse($request, $feed['body'], $feed['lastModified']);
    }

    public function feeds(Request $request): JsonResponse
    {
        return response()->json($this->calendar->listFeeds($this->publicBaseUrl($request)));
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        $preview = $this->calendar->previewFeed($key, $this->publicBaseUrl($request));
        if ($preview === null) {
            return response()->json(['error' => 'Unknown calendar feed'], 404);
        }

        return response()->json($preview);
    }

    public function rebuildWindow(): JsonResponse
    {
        return response()->json($this->calendar->rebuildWindow());
    }

    private function publicBaseUrl(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/');
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
