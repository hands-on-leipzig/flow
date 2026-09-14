<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MNotice;
use App\Services\NoticeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class NoticeController extends Controller
{
    public function __construct(
        private NoticeService $notices,
    ) {}

    public function index(Event $event): JsonResponse
    {
        return response()->json($this->notices->payload($event, $this->adminToday()));
    }

    private function adminToday(): ?Carbon
    {
        $raw = request()->query('today');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if (! request()->user()?->isFlowAdmin()) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $date->startOfDay();
    }

    public function hide(Event $event, MNotice $notice): Response
    {
        $this->notices->hide($event, $notice);

        return response()->noContent();
    }

    public function restore(Event $event): Response
    {
        $this->notices->restore($event);

        return response()->noContent();
    }
}
