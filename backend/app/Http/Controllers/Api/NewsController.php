<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    public function publicIndex(Request $request)
    {
        return response()->json(News::where('status', 'published')->orderBy('published_at', 'desc')->paginate(20));
    }

    public function publicShow(News $news)
    {
        if ($news->status !== 'published') {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($news);
    }

    public function index(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(News::where('status', 'published')->orderBy('published_at', 'desc')->paginate(20));
        }

        return response()->json(News::orderBy('published_at', 'desc')->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string'],
            'content' => ['required', 'string'],
            'published_at' => ['nullable', 'date'],
            'author_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        return response()->json(News::create($data), 201);
    }

    public function show(Request $request, News $news)
    {
        if ($news->status !== 'published' && ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($news);
    }

    public function update(Request $request, News $news)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'published_at' => ['nullable', 'date'],
            'author_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $news->update($data);

        return response()->json($news);
    }

    public function destroy(Request $request, News $news)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $news->delete();

        return response()->json(['success' => true]);
    }
}
