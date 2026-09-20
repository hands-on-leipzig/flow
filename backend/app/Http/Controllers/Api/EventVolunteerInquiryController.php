<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\VolunteerInquiry;
use App\Services\VolunteerInquiryService;
use Illuminate\Http\JsonResponse;

class EventVolunteerInquiryController extends Controller
{
    public function __construct(
        private VolunteerInquiryService $inquiries,
    ) {}

    public function index(Event $event): JsonResponse
    {
        return response()->json([
            'inquiries' => $this->inquiries->pendingForEvent($event),
        ]);
    }

    public function accept(Event $event, VolunteerInquiry $inquiry): JsonResponse
    {
        return response()->json([
            'inquiry' => $this->inquiries->accept($event, $inquiry),
        ]);
    }

    public function decline(Event $event, VolunteerInquiry $inquiry): JsonResponse
    {
        return response()->json([
            'inquiry' => $this->inquiries->decline($event, $inquiry),
        ]);
    }
}
