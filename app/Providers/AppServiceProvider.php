<?php

namespace App\Providers;

use App\Services\GeminiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('catalog-search', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip())
            ->response(fn (Request $request, array $headers) => $this->rateLimitResponse($request, $headers, 'You have reached the catalog search limit.')));

        RateLimiter::for('recommendation-generation', fn (Request $request) => Limit::perMinute(10)
            ->by('user:'.$request->user()->id)
            ->response(fn (Request $request, array $headers) => $this->rateLimitResponse($request, $headers, 'You have reached the recommendation limit.')));

        RateLimiter::for('chatbot-messages', fn (Request $request) => Limit::perMinute(20)
            ->by('user:'.$request->user()->id)
            ->response(fn (Request $request, array $headers) => $this->rateLimitResponse($request, $headers, 'You have reached the chat message limit.')));

        RateLimiter::for('room-uploads', fn (Request $request) => Limit::perMinutes(10, 5)
            ->by('user:'.$request->user()->id)
            ->response(fn (Request $request, array $headers) => $this->rateLimitResponse($request, $headers, 'You have reached the room upload limit.')));
        RateLimiter::for('ai-room-actions', fn (Request $request) => [
            Limit::perMinute(5)->by('minute:'.$request->user()->id),
            Limit::perDay(10)->by('day:'.$request->user()->id),
        ]);
        RateLimiter::for('ai-status', fn (Request $request) => Limit::perMinute(120)->by('user:'.$request->user()->id));
        RateLimiter::for('email-requests', fn () => [
            Limit::perMinute(30)->by('project-minute'),
            Limit::perDay(300)->by('project-day'),
        ]);

        foreach ([
            'profile-updates' => [1, 10, 'You have reached the profile update limit.'],
            'password-changes' => [15, 5, 'You have reached the password change limit.'],
            'account-deletions' => [15, 3, 'You have reached the account deletion limit.'],
            'reservation-requests' => [1, 5, 'You have reached the reservation request limit.'],
            'reservation-cancellations' => [1, 10, 'You have reached the reservation cancellation limit.'],
        ] as $name => [$minutes, $attempts, $message]) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinutes($minutes, $attempts)
                ->by('user:'.$request->user()->id)
                ->response(fn (Request $request, array $headers) => $this->rateLimitResponse($request, $headers, $message)));
        }
    }

    private function rateLimitResponse(Request $request, array $headers, string $message): Response
    {
        $message .= ' Please try again in '.$headers['Retry-After'].' seconds.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 429, $headers)
            : response()->view('errors.429', compact('message'), 429, $headers);
    }
}
