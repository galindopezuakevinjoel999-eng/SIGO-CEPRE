<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\HomeBlockRequest;
use App\Models\Content\HomeBlock;
use Illuminate\Support\Facades\Gate;

/** /api/admin/content/home-blocks — bloques de la página de inicio (solo admin). */
class HomeBlockController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', HomeBlock::class);

        return response()->json(HomeBlock::orderBy('order')->get());
    }

    public function show(string $id)
    {
        Gate::authorize('view', HomeBlock::class);

        return response()->json(HomeBlock::findOrFail($id));
    }

    public function store(HomeBlockRequest $request)
    {
        return response()->json(HomeBlock::create($request->validated()), 201);
    }

    public function update(HomeBlockRequest $request, string $id)
    {
        $block = HomeBlock::findOrFail($id);
        $block->update($request->validated());

        return response()->json($block);
    }

    public function destroy(string $id)
    {
        Gate::authorize('delete', HomeBlock::class);

        HomeBlock::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }
}
