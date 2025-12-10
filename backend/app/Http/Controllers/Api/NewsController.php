<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NewsController extends Controller
{
    /**
     * Get all news (admin only)
     */
    public function index()
    {
        $news = News::orderBy('created_at', 'desc')->get();
        
        $totalUsers = User::count();
        
        $newsWithStats = $news->map(function ($item) use ($totalUsers) {
            $readCount = NewsUser::where('news_id', $item->id)->count();
            
            return [
                'id' => $item->id,
                'title' => $item->title,
                'text' => $item->text,
                'link' => $item->link,
                'created_at' => $item->created_at,
                'read_count' => $readCount,
                'total_users' => $totalUsers,
            ];
        });

        return response()->json($newsWithStats);
    }

    /**
     * Get oldest unread news for current user
     */
    public function getUnreadNews(Request $request)
    {
        $userId = $request->user()->id;

        // Find oldest news that this user hasn't read
        $unreadNews = News::whereNotExists(function ($query) use ($userId) {
            $query->select(DB::raw(1))
                ->from('news_user')
                ->whereColumn('news_user.news_id', 'news.id')
                ->where('news_user.user_id', $userId);
        })
        ->orderBy('created_at', 'asc')
        ->first();

        if (!$unreadNews) {
            return response()->json(null);
        }

        return response()->json([
            'id' => $unreadNews->id,
            'title' => $unreadNews->title,
            'text' => $unreadNews->text,
            'link' => $unreadNews->link,
            'created_at' => $unreadNews->created_at,
        ]);
    }

    /**
     * Create new news (admin only)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'text' => 'required|string',
            'link' => 'nullable|string|max:500',
        ]);

        $news = News::create($validated);

        return response()->json($news, 201);
    }

    /**
     * Mark news as read for current user
     */
    public function markAsRead(Request $request, int $id)
    {
        $userId = $request->user()->id;

        // Check if news exists
        $news = News::find($id);
        if (!$news) {
            return response()->json(['error' => 'News not found'], 404);
        }

        // Create or update read record
        NewsUser::updateOrCreate(
            ['user_id' => $userId, 'news_id' => $id],
            ['read_at' => now()]
        );

        return response()->json(['success' => true]);
    }

    /**
     * Delete news (admin only)
     */
    public function destroy(int $id)
    {
        $news = News::find($id);
        
        if (!$news) {
            return response()->json(['error' => 'News not found'], 404);
        }

        $news->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get read statistics for a news item (admin only)
     */
    public function stats(int $id)
    {
        $news = News::find($id);
        
        if (!$news) {
            return response()->json(['error' => 'News not found'], 404);
        }

        $readCount = NewsUser::where('news_id', $id)->count();
        $totalUsers = User::count();

        return response()->json([
            'news_id' => $id,
            'read_count' => $readCount,
            'total_users' => $totalUsers,
        ]);
    }
}
