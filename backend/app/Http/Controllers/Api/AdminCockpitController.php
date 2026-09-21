<?php

namespace App\Http\Controllers\Api;

use App\Export\Admin\AdminCockpitSpreadsheetSource;
use App\Export\Spreadsheet\SpreadsheetResponse;
use App\Http\Controllers\Controller;
use App\Services\AdminCockpitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminCockpitController extends Controller
{
    public function __construct(
        private AdminCockpitService $cockpit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $seasonId = $this->cockpit->resolveSeasonId($request->query('season'));

        return response()->json($this->cockpit->payload($seasonId));
    }

    public function spreadsheet(Request $request): Response
    {
        $seasonId = $this->cockpit->resolveSeasonId($request->query('season'));
        $payload = $this->cockpit->payload($seasonId);
        $programIds = $this->programIds($request->query('programs'));
        $events = $this->cockpit->filterUpcoming(
            $payload['events'],
            $request->query('upcoming') === '1',
        );
        $events = $this->cockpit->filterPrograms($events, $programIds);
        $events = $this->cockpit->filterWithoutPlan(
            $events,
            $request->query('without_plan') === '1',
        );
        $events = $this->cockpit->filterHelferliste(
            $events,
            (string) $request->query('helferliste', 'both'),
        );
        $events = $this->cockpit->sortEvents(
            $events,
            (string) $request->query('sort', 'date'),
            (string) $request->query('dir', 'asc'),
        );

        return SpreadsheetResponse::download(
            (new AdminCockpitSpreadsheetSource($events))->document(),
        );
    }

    /**
     * @return list<int>
     */
    private function programIds(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn (int $id) => $id > 0,
        ));
    }
}
