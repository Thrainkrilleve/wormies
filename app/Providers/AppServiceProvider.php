<?php

declare(strict_types=1);

namespace App\Providers;

use App\DTO\CTA;
use App\Models\ServerStatus;
use App\Policies\PersonalAccessTokenPolicy;
use App\Services\EsiNameResolver;
use App\Services\NameResolver;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Eveonline\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

use function Laravel\Prompts\info;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NameResolver::class, EsiNameResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        TrustProxies::at(\App\Http\TrustedProxies::configured());

        $this->registerNotificationMacro();
        $this->registerRateLimiters();

        Model::shouldBeStrict();
        Model::unguard();
        Model::automaticallyEagerLoadRelationships();

        Vite::useAggressivePrefetching();

        if (! app()->environment(['local', 'testing'])) {
            URL::forceHttps();
        }

        Date::use(CarbonImmutable::class);

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('eveonline', Provider::class);
            $event->extendSocialite('discord', DiscordProvider::class);
        });

        Gate::policy(PersonalAccessToken::class, PersonalAccessTokenPolicy::class);

        $this->reloads('app:restart-killmail-listener', 'killmails');
        $this->reloads('discord:restart', 'discord');

        $this->registerScheduleMacros();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user() !== null ? (string) $request->user()->getAuthIdentifier() : (string) $request->ip()
        ));
    }

    private function registerNotificationMacro(): void
    {
        RedirectResponse::macro('notify', function (string $title, string $message = '', string $type = 'success', ?CTA $action = null): RedirectResponse {
            if (request()->boolean('silent')) {
                return $this;
            }

            $notification = [
                'id' => (string) Str::uuid(),
                'title' => $title,
                'message' => $message,
                'type' => $type,
            ];

            if ($action instanceof CTA) {
                $notification['action'] = $action->toArray();
            }

            return $this->with('notification', $notification);
        });
    }

    private function registerScheduleMacros(): void
    {
        ScheduledEvent::macro('notDuringDowntime', fn () => $this->skip(
            function (): bool {
                $status = ServerStatus::query()->latest()->first();

                $should_skip = $status !== null && $status->players === 0;

                if ($should_skip) {
                    info('Skipping scheduled task during downtime (0 players online)');

                    return true;
                }

                return false;
            }
        ));
    }
}
