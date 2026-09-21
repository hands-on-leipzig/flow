<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Catalog\MailNotificationCatalog;
use App\Services\MailTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class MailController extends Controller
{
    public function __construct(
        private readonly MailTestService $mailTest,
        private readonly MailNotificationCatalog $catalog,
    ) {}

    public function status(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        return response()->json($this->mailTest->status());
    }

    public function notifications(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        return response()->json([
            'notifications' => array_map(
                fn ($definition) => $definition->toArray(),
                $this->catalog->all(),
            ),
        ]);
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        try {
            return response()->json($this->catalog->preview($key));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'Unbekannte Notification.'], 404);
        }
    }

    public function sendTest(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'key' => 'nullable|string|max:80',
        ], [
            'email.required' => 'Bitte eine Empfängeradresse eintragen.',
            'email.email' => 'Bitte eine gültige E-Mail-Adresse eintragen.',
        ]);

        $key = $validated['key'] ?? 'admin-test';

        try {
            $this->catalog->sendSample($key, $validated['email']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'Unbekannte Notification.'], 404);
        } catch (TransportExceptionInterface|Throwable $e) {
            return response()->json([
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Senden fehlgeschlagen.',
            ], 422);
        }

        return response()->json(['success' => true, 'key' => $key]);
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
