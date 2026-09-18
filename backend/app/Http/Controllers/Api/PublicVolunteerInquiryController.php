<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VolunteerInquiryService;
use App\Support\DrahtContactId;
use App\Support\KeycloakAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicVolunteerInquiryController extends Controller
{
    public function __construct(
        private VolunteerInquiryService $inquiries,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => 'required|integer',
            'role' => 'required|string|max:255',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'mobile' => 'nullable|string|max:50',
            'message' => 'nullable|string|max:2000',
        ]);

        $validated['draht_id'] = DrahtContactId::fromClaims(
            KeycloakAccessToken::optionalClaims($request) ?? []
        );

        $result = $this->inquiries->submit($validated);

        return response()->json([
            'ok' => true,
            'inquiry_id' => $result['inquiry_id'],
        ], 201);
    }
}
