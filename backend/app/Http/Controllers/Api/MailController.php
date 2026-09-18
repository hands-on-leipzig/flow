<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MailTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Exception\TransportException;

class MailController extends Controller
{
    public function __construct(
        private readonly MailTestService $mailTest,
    ) {}

    public function status(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        return response()->json($this->mailTest->status());
    }

    public function sendTest(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Bitte eine Empfängeradresse eintragen.',
            'email.email' => 'Bitte eine gültige E-Mail-Adresse eintragen.',
        ]);

        try {
            $this->mailTest->sendTest($validated['email']);
        } catch (TransportException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isFlowAdmin()) {
            return response()->json(['error' => 'Forbidden - admin role required'], 403);
        }

        return null;
    }
}
