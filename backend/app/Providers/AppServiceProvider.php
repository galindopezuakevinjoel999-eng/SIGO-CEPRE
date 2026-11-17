<?php

namespace App\Providers;

use App\Models\Content\Announcement;
use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use App\Models\Content\News;
use App\Models\User;
use App\Policies\ContentPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Los modelos de contenido viven en App\Models\Content y comparten una
        // sola policy (solo admin); el descubrimiento automático no la encontraría.
        foreach ([News::class, Announcement::class, Faq::class, HomeBlock::class] as $model) {
            Gate::policy($model, ContentPolicy::class);
        }

        // Dashboard y reportes: administración y coordinación.
        Gate::define('view-reports', fn (User $user) => $user->isAdmin() || $user->isCoordinador()
            ? Response::allow()
            : Response::deny('Solo administración y coordinación pueden ver reportes.'));

        // Indicadores de la investigación (tesis): solo administración.
        Gate::define('view-research-indicators', fn (User $user) => $user->isAdmin()
            ? Response::allow()
            : Response::deny('Solo administración puede ver los indicadores de investigación.'));
    }
}
