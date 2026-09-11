<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\MNotice;
use App\Services\NoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class NoticeController extends Controller
{
    public function __construct(
        private NoticeService $notices,
    ) {}

    public function index(Event $event): JsonResponse
    {
        return response()->json($this->notices->payload($event));
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
