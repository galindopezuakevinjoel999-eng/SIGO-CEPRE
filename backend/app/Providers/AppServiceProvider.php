<?php

namespace App\Providers;

use App\Models\Content\Announcement;
use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use App\Models\Content\News;
use App\Policies\ContentPolicy;
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
    }
}
