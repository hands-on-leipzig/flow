<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logo;
use Illuminate\Http\Request;

class LogoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $logos = Logo::with('events')->whereIn('regional_partner', $user->regionalPartners->pluck('id'))->get();
        return response()->json($logos);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|image|max:2048',
            'regional_partner' => 'required|exists:regional_partners,id',
        ]);

        $path = $request->file('file')->store('logos', 'public');

        $logo = Logo::create([
            'file_path' => $path,
            'regional_partner' => $request->regional_partner,
        ]);

        return response()->json($logo);
    }

    public function update(Request $request, Logo $logo)
    {
        $this->authorize('update', $logo);
        $logo->update($request->only(['title', 'link']));
        return response()->json($logo);
    }

    public function destroy(Logo $logo)
    {
        $this->authorize('delete', $logo);
        $logo->delete();
        return response()->json();
    }

    public function toggleEvent(Request $request, Logo $logo)
    {
        $eventId = $request->input('event_id');
        $logo->events()->toggle($eventId);
        return response()->json(['status' => 'toggled']);
    }
}
