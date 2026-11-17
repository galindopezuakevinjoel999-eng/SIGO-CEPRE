<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\AnnouncementRequest;
use App\Models\Content\Announcement;
use App\Services\PublicContentFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** /api/admin/content/announcements — CRUD de avisos y comunicados (solo admin). */
class AnnouncementController extends Controller
{
    public function __construct(private PublicContentFiles $files) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Announcement::class);

        return response()->json(
            Announcement::query()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
                ->orderByDesc('created_at')
                ->paginate(20)
        );
    }

    public function show(string $id)
    {
        Gate::authorize('view', Announcement::class);

        return response()->json(Announcement::findOrFail($id));
    }

    public function store(AnnouncementRequest $request)
    {
        $announcement = new Announcement($this->fields($request));
        $announcement->author = ['id' => $request->user()->id, 'name' => $request->user()->name];

        if ($request->hasFile('attachments')) {
            $announcement->attachments = $this->storeAttachments($request);
        }

        $announcement->save();

        return response()->json($announcement, 201);
    }

    /** Enviar adjuntos nuevos reemplaza los anteriores (que se borran del disco). */
    public function update(AnnouncementRequest $request, string $id)
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->fill($this->fields($request));

        $previous = [];
        if ($request->hasFile('attachments')) {
            $previous = array_column($announcement->attachments ?? [], 'path');
            $announcement->attachments = $this->storeAttachments($request);
        }

        $announcement->save();
        $this->files->delete($previous);

        return response()->json($announcement);
    }

    public function destroy(string $id)
    {
        Gate::authorize('delete', Announcement::class);

        $announcement = Announcement::findOrFail($id);
        $announcement->delete();
        $this->files->delete(array_column($announcement->attachments ?? [], 'path'));

        return response()->json(['success' => true]);
    }

    private function fields(AnnouncementRequest $request): array
    {
        $data = $request->safe()->except(['attachments', 'audience']);

        if ($audience = $request->audience()) {
            $data['audience'] = $audience;
        }

        return $data;
    }

    /** @return list<array{name: string, path: string, url: string}> */
    private function storeAttachments(AnnouncementRequest $request): array
    {
        return array_map(
            fn ($file) => $this->files->store($file, 'announcements'),
            $request->file('attachments'),
        );
    }
}
