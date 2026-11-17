<?php

namespace App\Http\Controllers;

use App\Models\Content\Announcement;
use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use App\Models\Content\News;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sitio público del CEPRE (RF42–RF45, RF47). No requiere sesión.
 * Solo expone contenido publicado y vigente; nunca borradores ni datos del autor
 * más allá de su nombre.
 */
class PublicController extends Controller
{
    public const HOME_NEWS = 3;

    public const HOME_ANNOUNCEMENTS = 5;

    /** Página de inicio: bloques activos, últimas noticias y comunicados vigentes. */
    public function home()
    {
        return response()->json([
            'contact' => [
                'office' => SystemSetting::getValue('contact_office'),
                'office_hours' => SystemSetting::getValue('contact_office_hours'),
                'phones' => SystemSetting::getValue('contact_phones') ?? [],
                'email' => SystemSetting::getValue('contact_email'),
                'payment_place' => SystemSetting::getValue('payment_place'),
            ],
            'blocks' => HomeBlock::where('active', true)->orderBy('order')->get(),
            'latest_news' => News::published()->orderByDesc('published_at')->limit(self::HOME_NEWS)->get()->map->only($this->newsSummaryFields()),
            'announcements' => $this->currentAnnouncements()->take(self::HOME_ANNOUNCEMENTS)->values(),
        ]);
    }

    public function news(Request $request)
    {
        $request->validate(['tag' => ['sometimes', 'string', 'max:40']]);

        $page = News::published()
            ->when($request->filled('tag'), fn ($q) => $q->where('tags', $request->input('tag')))
            ->orderByDesc('published_at')
            ->paginate(12);

        $page->getCollection()->transform(fn (News $news) => $news->only($this->newsSummaryFields()));

        return response()->json($page);
    }

    public function newsShow(string $slug)
    {
        $news = News::published()->where('slug', $slug)->first() ?? abort(404, 'Noticia no encontrada.');

        return response()->json($news->makeHidden('author')->setAttribute('author_name', $news->author['name'] ?? null));
    }

    /**
     * Comunicados publicados y vigentes. Con ?group=A y/o ?career=SIS devuelve
     * los dirigidos a todos más los de ese grupo o carrera; sin filtros, todos.
     */
    public function announcements(Request $request)
    {
        $request->validate([
            'group' => ['sometimes', 'string', 'size:1'],
            'career' => ['sometimes', 'string', 'max:10'],
            'type' => ['sometimes', Rule::in(Announcement::TYPES)],
        ]);

        return response()->json($this->currentAnnouncements(
            $request->input('group'),
            $request->input('career'),
            $request->input('type'),
        )->values());
    }

    public function faqs(Request $request)
    {
        $request->validate(['category' => ['sometimes', Rule::in(Faq::CATEGORIES)]]);

        return response()->json(
            Faq::where('active', true)
                ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
                ->orderBy('category')
                ->orderBy('order')
                ->get(['question', 'answer', 'category', 'keywords', 'order'])
        );
    }

    /** Vigentes, de mayor a menor prioridad y, dentro de cada una, los más recientes primero. */
    private function currentAnnouncements(?string $group = null, ?string $career = null, ?string $type = null)
    {
        return Announcement::current()
            ->when($group || $career, fn ($q) => $q->forAudience($group, $career))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderByDesc('valid_from')
            ->get()
            ->sortBy(fn (Announcement $a) => $a->priorityRank(), SORT_REGULAR)
            ->map(fn (Announcement $a) => $a->makeHidden(['author', 'status']));
    }

    /** Lo que se muestra en listados (sin el cuerpo completo). */
    private function newsSummaryFields(): array
    {
        return ['id', 'title', 'slug', 'summary', 'cover_image_url', 'tags', 'published_at'];
    }
}
