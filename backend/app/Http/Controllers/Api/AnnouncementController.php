<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->isAdmin() || $request->user()->isCoordinador()) {
            return response()->json(Announcement::orderBy('published_at', 'desc')->paginate(20));
        }

        return response()->json(Announcement::where('status', 'published')->orderBy('published_at', 'desc')->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string'],
            'content' => ['required', 'string'],
            'published_at' => ['nullable', 'date'],
            'author_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        return response()->json(Announcement::create($data), 201);
    }

    public function show(Request $request, Announcement $announcement)
    {
        if ($announcement->status !== 'published' && ! $request->user()->isAdmin() && ! $request->user()->isCoordinador()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($announcement);
    }

    public function update(Request $request, Announcement $announcement)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'published_at' => ['nullable', 'date'],
            'author_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $announcement->update($data);

        return response()->json($announcement);
    }

    public function destroy(Request $request, Announcement $announcement)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $announcement->delete();

        return response()->json(['success' => true]);
    }
}
