<?php

namespace App\Providers;

use App\Domain\Payments\Signature\HmacSignatureVerifier;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Documents\MpdfEngine;
use App\Infrastructure\Documents\PdfEngine;
use App\Infrastructure\Payments\AirtelMoneyAdapter;
use App\Infrastructure\Payments\CardAdapter;
use App\Infrastructure\Payments\MockPaymentProvider;
use App\Infrastructure\Payments\NitaAdapter;
use App\Infrastructure\Payments\PaymentManager;
use App\Infrastructure\Payments\WaveAdapter;
use App\Policies\StaffPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(PdfEngine::class, MpdfEngine::class);

        // Le fournisseur de paiement FICTIF ne doit jamais fonctionner en production, même si .env l'active par erreur.
        if (config('app.env') === 'production' && config('payments.mock.enabled') && ! env('PAYMENT_MOCK_ALLOW_IN_PRODUCTION')) {
            config(['payments.mock.enabled' => false]);
        }

        // Journaux structurés (une ligne JSON par événement, sur stderr : collecte par Docker). LOG_CHANNEL=json en production.
        config(['logging.channels.json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => \Monolog\Handler\StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
            'formatter' => \Monolog\Formatter\JsonFormatter::class,
            'processors' => [\App\Support\LogRedactor::class],
        ]]);

        // Résolu paresseusement : la config est lue au premier usage (les tests peuvent la surcharger avant).
        $this->app->singleton(PaymentManager::class, function () {
            $mock = new MockPaymentProvider(
                (string) config('payments.mock.secret'),
                (bool) config('payments.mock.enabled'),
                new HmacSignatureVerifier((int) config('payments.webhook_tolerance_seconds')),
            );

            return new PaymentManager([
                $mock->code() => $mock,
                'wave' => new WaveAdapter,
                'nita' => new NitaAdapter,
                'airtel' => new AirtelMoneyAdapter,
                'card' => new CardAdapter,
            ]);
        });
    }

    public function boot(): void
    {
        if (config('app.env') === 'production' || env('APP_FORCE_HTTPS')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Schema::defaultStringLength(191);

        // Agents du tenant : pas de modèle propre (ce sont des User), donc des gates nommés.
        Gate::define('staff.view-any', [StaffPolicy::class, 'viewAny']);
        Gate::define('staff.create', [StaffPolicy::class, 'create']);
        Gate::define('staff.manage', [StaffPolicy::class, 'manage']);

        // Brute force : une limite PAR IDENTIFIANT (10/min) + un plafond par IP généreux (60/min).
        // Beaucoup d'utilisateurs partagent la même IP publique (CGNAT des opérateurs) : une limite par IP seule
        // bloquerait des clients légitimes, et un identifiant vide ne doit pas créer un compteur partagé.
        RateLimiter::for('auth', function (Request $request) {
            $limits = [Limit::perMinute(60)->by('ip:'.$request->ip())];

            $identifier = mb_strtolower(trim((string) ($request->input('identifier') ?? $request->input('email') ?? $request->input('phone') ?? '')));
            if ($identifier !== '') {
                $limits[] = Limit::perMinute(10)->by('id:'.hash('sha256', $identifier));
            }

            return $limits;
        });

        RateLimiter::for('api', function (Request $request) {
            // Les webhooks ont leur propre limiteur (ci-dessous) : tous les événements d'un fournisseur arrivent
            // depuis quelques IP, le plafond générique de 120/min les étranglerait et ferait perdre des paiements.
            if ($request->is('api/v1/payments/webhooks/*') || $request->is('api/v1/agent/*') || $request->is('api/v1/portal/*')) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->user()?->getKey() ?: $request->ip());
        });

        // Webhooks de paiement : non authentifiés (signature), plafond par IP.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('wh:'.$request->ip()));

        // Agents routeur : un plafond par jeton (heartbeat 2/min + poll 6/min + résultats => 180/min est large) ;
        // l'enrôlement est limité par IP (code à 100 bits : le plafond protège surtout la charge).
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute(180)->by('agent:'.hash('sha256', (string) $request->bearerToken())));
        // Génération de PDF / CSV : coûteuse en CPU, plafonnée par utilisateur.
        RateLimiter::for('documents', fn (Request $request) => Limit::perMinute(20)->by('doc:'.($request->user()?->getKey() ?: $request->ip())));
        // Portail public : plafond par IP (les clients d'un même WiFi partagent parfois une IP NAT : valeurs larges, à ajuster).
        RateLimiter::for('ops', fn (Request $request) => Limit::perMinute(30)->by('ops:'.$request->ip()));
        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(120)->by('portal:'.$request->ip()));
        RateLimiter::for('portal-orders', fn (Request $request) => Limit::perMinute(10)->by('portal-order:'.$request->ip()));
        RateLimiter::for('agent-enroll', fn (Request $request) => Limit::perMinute(10)->by('enroll:'.$request->ip()));
    }
}
