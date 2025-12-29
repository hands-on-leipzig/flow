<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class LogoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        Log::debug($user);
        Log::debug($user->regionalPartners);
        
        // Get logos with their event relationships, including sort_order
        $logos = Logo::with(['events' => function($query) {
            $query->orderBy('event_logo.sort_order');
        }])->where('regional_partner', $user->selection_regional_partner)->get();
        
        return response()->json($logos);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|image|max:2048',
            'regional_partner' => 'required|exists:regional_partner,id',
        ]);

        $path = $request->file('file')->store('logos', 'public');

        $logo = Logo::create([
            'path' => $path,
            'regional_partner' => $validated["regional_partner"],
        ]);

        return response()->json($logo);
    }

    public function update(Request $request, Logo $logo)
    {
        $logo->update($request->only(['title', 'link']));
        return response()->json($logo);
    }

    public function destroy(Logo $logo)
    {
        try {
            $this->authorize('delete', $logo);
            $logo->delete();
            return response()->json();
        } catch (\Exception $e) {
            $translated = \App\Services\ErrorTranslationService::translateException($e);
            return response()->json([
                'message' => $translated['message'],
                'details' => $translated['details'],
            ], 500);
        }
    }

    public function toggleEvent(Request $request, Logo $logo)
    {
        $eventId = $request->input('event_id');
        $logo->events()->toggle($eventId);
        return response()->json(['status' => 'toggled']);
    }

    public function updateSortOrder(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:event,id',
            'logo_orders' => 'required|array',
            'logo_orders.*.logo_id' => 'required|integer|exists:logo,id',
            'logo_orders.*.sort_order' => 'required|integer|min:0'
        ]);

        $eventId = $validated['event_id'];
        
        // Update sort order for each logo
        foreach ($validated['logo_orders'] as $logoOrder) {
            \DB::table('event_logo')
                ->where('event', $eventId)
                ->where('logo', $logoOrder['logo_id'])
                ->update(['sort_order' => $logoOrder['sort_order']]);
        }

        return response()->json(['status' => 'sort_order_updated']);
    }

    /**
     * Get logos for a specific event (public endpoint)
     */
    public function getEventLogos($eventId)
    {
        // Get logos assigned to this event, ordered by sort_order from pivot table
        $logos = DB::table('logo')
            ->join('event_logo', 'logo.id', '=', 'event_logo.logo')
            ->where('event_logo.event', $eventId)
            ->select('logo.id', 'logo.title', 'logo.link', 'logo.path', 'event_logo.sort_order')
            ->orderBy('event_logo.sort_order')
            ->get()
            ->map(function($logo) {
                return [
                    'id' => $logo->id,
                    'title' => $logo->title,
                    'link' => $logo->link,
                    'path' => $logo->path,
                    'url' => asset('storage/' . $logo->path),
                    'sort_order' => $logo->sort_order
                ];
            });

        return response()->json($logos);
    }

    /**
     * Clean up orphaned logos - remove DB entries without files and files without DB entries
     */
    public function cleanupOrphanedLogos()
    {
        try {
            $deletedDbEntries = 0;
            $deletedFiles = 0;
            $errors = [];

            // Get all logos from database
            $logos = Logo::all();
            $storagePath = storage_path('app/public');

            // Check each logo in DB - if file doesn't exist, delete DB entry
            foreach ($logos as $logo) {
                $filePath = $storagePath . '/' . $logo->path;
                
                if (!File::exists($filePath)) {
                    try {
                        // Delete related event_logo entries first (cascade)
                        DB::table('event_logo')->where('logo', $logo->id)->delete();
                        $logo->delete();
                        $deletedDbEntries++;
                        Log::info("Deleted orphaned logo DB entry: ID {$logo->id}, path: {$logo->path}");
                    } catch (\Exception $e) {
                        $errors[] = "Failed to delete logo DB entry ID {$logo->id}: " . $e->getMessage();
                        Log::error("Failed to delete logo DB entry", [
                            'logo_id' => $logo->id,
                            'path' => $logo->path,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Check all files in logos directory - if no DB entry exists, delete file
            $logosDirectory = $storagePath . '/logos';
            if (File::isDirectory($logosDirectory)) {
                $files = File::files($logosDirectory);
                
                foreach ($files as $file) {
                    $relativePath = 'logos/' . $file->getFilename();
                    
                    // Check if this file has a corresponding DB entry
                    $logoExists = Logo::where('path', $relativePath)->exists();
                    
                    if (!$logoExists) {
                        try {
                            // Only delete user-uploaded logos (in logos/ directory)
                            // System logos would be in other directories
                            File::delete($file->getPathname());
                            $deletedFiles++;
                            Log::info("Deleted orphaned logo file: {$relativePath}");
                        } catch (\Exception $e) {
                            $errors[] = "Failed to delete logo file {$relativePath}: " . $e->getMessage();
                            Log::error("Failed to delete logo file", [
                                'path' => $relativePath,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Logo cleanup completed',
                'deleted_db_entries' => $deletedDbEntries,
                'deleted_files' => $deletedFiles,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            Log::error('Logo cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Logo cleanup failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
