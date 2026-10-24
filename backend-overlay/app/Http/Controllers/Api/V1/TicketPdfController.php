<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\DocumentLabels;
use App\Domain\Documents\TicketLayout;
use App\Domain\Network\VoucherStatus;
use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherBatch;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\PlanEntitlements;
use App\Support\ApiException;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Export PDF de tickets : A4, A5, individuel, thermique ; QR optionnel ; 4 langues. */
class TicketPdfController extends Controller
{
    /** Un ticket n'est imprimable que s'il est utilisable par un client : en stock ou vendu, ET créé sur le routeur. */
    private const PRINTABLE = [VoucherStatus::AVAILABLE, VoucherStatus::VIP, VoucherStatus::SOLD];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly PlanEntitlements $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): Response
    {
        Gate::authorize('viewAny', Voucher::class);
        $max = (int) config('documents.max_tickets_per_pdf');

        $data = $request->validate([
            'format' => ['required', Rule::in(TicketLayout::FORMATS)],
            'thermal_width' => ['nullable', Rule::in(config('documents.thermal_widths'))],
            'locale' => ['nullable', Rule::in(DocumentLabels::LOCALES)],
            'include_qr' => ['nullable', 'boolean'],
            'batch_id' => ['nullable', 'uuid', Rules::ownedBy(VoucherBatch::class)],
            'voucher_ids' => ['nullable', 'array', 'min:1', 'max:'.$max],
            'voucher_ids.*' => ['uuid', 'distinct'],
            'router_id' => ['nullable', 'uuid', Rules::ownedBy(Router::class)],
            'profile_id' => ['nullable', 'uuid', Rules::ownedBy(HotspotProfile::class)],
        ]);

        $selections = (isset($data['batch_id']) ? 1 : 0) + (isset($data['voucher_ids']) ? 1 : 0)
            + ((isset($data['router_id']) || isset($data['profile_id'])) ? 1 : 0);
        if ($selections !== 1 || (isset($data['router_id']) xor isset($data['profile_id']))) {
            throw ValidationException::withMessages(['batch_id' => 'Choisissez UNE sélection : batch_id, voucher_ids, ou router_id + profile_id.']);
        }

        $withQr = array_key_exists('include_qr', $data) && $data['include_qr'] !== null
            ? (bool) $data['include_qr']
            : $this->entitlements->hasFeature('qr');
        if ($withQr) {
            $this->entitlements->assertFeature('qr');
        }
        $this->entitlements->assertFeature('pdf');

        $query = Voucher::query()
            ->where('sync_status', Voucher::SYNC_SYNCED)
            ->whereIn('status', self::PRINTABLE)
            ->when($data['batch_id'] ?? null, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($data['voucher_ids'] ?? null, fn ($q, $v) => $q->whereIn('id', $v))
            ->when($data['router_id'] ?? null, fn ($q, $v) => $q->where('router_id', $v)->where('profile_id', $data['profile_id']))
            ->orderBy('created_at')->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            throw new ApiException('no_printable_tickets', 'Aucun ticket imprimable dans cette sélection.', 422);
        }
        if ($total > $max) {
            throw new ApiException('too_many_tickets', 'Trop de tickets pour un seul PDF.', 422, ['max' => $max, 'selected' => $total]);
        }

        $vouchers = $query->get();

        // Identifiants inconnus ou d'un autre tenant : même erreur que pour une ressource inexistante.
        if (isset($data['voucher_ids'])) {
            $known = Voucher::whereIn('id', $data['voucher_ids'])->count();
            if ($known !== count($data['voucher_ids'])) {
                throw ValidationException::withMessages(['voucher_ids' => 'Un ou plusieurs tickets sont introuvables.']);
            }
        }

        $user = $request->user();
        $tenant = Tenant::findOrFail($user->tenant_id);
        $locale = DocumentLabels::normalize($data['locale'] ?? $user->locale);
        $pdf = $this->documents->ticketsPdf($tenant, $vouchers, $data['format'], (int) ($data['thermal_width'] ?? 58), $locale, $withQr);

        // Les codes sont des secrets d'accès : on journalise le NOMBRE, jamais les codes.
        $this->audit->log('tickets.exported', $user, null, [
            'count' => $vouchers->count(), 'format' => $data['format'], 'qr' => $withQr, 'locale' => $locale,
        ]);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tickets-'.now()->format('Ymd-His').'.pdf"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Tickets-Count' => (string) $vouchers->count(),
        ]);
    }
}
