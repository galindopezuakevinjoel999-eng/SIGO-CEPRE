<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\NewsRequest;
use App\Models\Content\News;
use App\Services\PublicContentFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/** /api/admin/content/news — CRUD de noticias (solo admin). */
class NewsController extends Controller
{
    public function __construct(private PublicContentFiles $files) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', News::class);

        return response()->json(
            News::query()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->orderByDesc('created_at')
                ->paginate(20)
        );
    }

    public function show(string $id)
    {
        Gate::authorize('view', News::class);

        return response()->json(News::findOrFail($id));
    }

    public function store(NewsRequest $request)
    {
        $data = $request->safe()->except(['cover_image', 'remove_cover_image']);
        $data['slug'] ??= News::uniqueSlug($data['title']);
        $data['author'] = ['id' => $request->user()->id, 'name' => $request->user()->name];

        $news = new News($data);
        $news->published_at = $this->publishedAt($news, $data);

        if ($request->hasFile('cover_image')) {
            $news->cover_image = $this->files->store($request->file('cover_image'), 'news')['path'];
        }

        $news->save();

        return response()->json($news, 201);
    }

    /** El slug no cambia al editar el título: la URL pública de la noticia se mantiene. */
    public function update(NewsRequest $request, string $id)
    {
        $news = News::findOrFail($id);
        $data = $request->safe()->except(['cover_image', 'remove_cover_image']);

        $news->fill($data);
        $news->published_at = $this->publishedAt($news, $data);

        $previous = null;
        if ($request->hasFile('cover_image')) {
            $previous = $news->cover_image;
            $news->cover_image = $this->files->store($request->file('cover_image'), 'news')['path'];
        } elseif ($request->boolean('remove_cover_image')) {
            $previous = $news->cover_image;
            $news->cover_image = null;
        }

        $news->save();
        $this->files->delete($previous); // la anterior se borra después de guardar la nueva

        return response()->json($news);
    }

    public function destroy(string $id)
    {
        Gate::authorize('delete', News::class);

        $news = News::findOrFail($id);
        $news->delete();
        $this->files->delete($news->cover_image);

        return response()->json(['success' => true]);
    }

    /** Al publicar sin fecha, se publica ahora. Una fecha futura la programa. */
    private function publishedAt(News $news, array $data): ?Carbon
    {
        if (array_key_exists('published_at', $data) && $data['published_at']) {
            return Carbon::parse($data['published_at']);
        }

        if ($news->status === 'published') {
            return $news->published_at ?? now();
        }

        return $news->published_at;
    }
}
