<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\FaqRequest;
use App\Models\Content\Faq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** /api/admin/content/faqs — CRUD de preguntas frecuentes (solo admin). */
class FaqController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Faq::class);

        return response()->json(
            Faq::query()
                ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
                ->orderBy('category')
                ->orderBy('order')
                ->get()
        );
    }

    public function show(string $id)
    {
        Gate::authorize('view', Faq::class);

        return response()->json(Faq::findOrFail($id));
    }

    public function store(FaqRequest $request)
    {
        return response()->json(Faq::create($request->validated()), 201);
    }

    public function update(FaqRequest $request, string $id)
    {
        $faq = Faq::findOrFail($id);
        $faq->update($request->validated());

        return response()->json($faq);
    }

    public function destroy(string $id)
    {
        Gate::authorize('delete', Faq::class);

        Faq::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }
}
