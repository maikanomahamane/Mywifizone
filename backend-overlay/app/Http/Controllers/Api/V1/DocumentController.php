<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Documents\DocumentLabels;
use App\Domain\Documents\ReceiptHtmlBuilder;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Reçu de vente et facture d'abonnement en PDF. */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents, private readonly AuditLogger $audit) {}

    public function receipt(Request $request, string $id): Response
    {
        $sale = Sale::findOrFail($id);
        Gate::authorize('view', $sale);   // un agent n'obtient que le reçu de SES ventes

        $data = $request->validate([
            'format' => ['nullable', Rule::in(ReceiptHtmlBuilder::FORMATS)],
            'thermal_width' => ['nullable', Rule::in(config('documents.thermal_widths'))],
            'locale' => ['nullable', Rule::in(DocumentLabels::LOCALES)],
        ]);

        $user = $request->user();
        $pdf = $this->documents->receiptPdf(
            Tenant::findOrFail($user->tenant_id), $sale,
            $data['format'] ?? 'thermal', (int) ($data['thermal_width'] ?? 58),
            DocumentLabels::normalize($data['locale'] ?? $user->locale),
        );

        return $this->pdf($pdf, 'recu-'.strtolower(substr((string) $sale->getKey(), 0, 8)));
    }

    public function invoice(Request $request, string $id): Response
    {
        $invoice = Invoice::with('plan')->findOrFail($id);

        $data = $request->validate(['locale' => ['nullable', Rule::in(DocumentLabels::LOCALES)]]);
        $user = $request->user();
        $pdf = $this->documents->invoicePdf(
            Tenant::findOrFail($user->tenant_id), $invoice,
            DocumentLabels::normalize($data['locale'] ?? $user->locale),
        );

        $this->audit->log('invoice.pdf_downloaded', $user, $invoice);

        return $this->pdf($pdf, 'facture-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) $invoice->number));
    }

    private function pdf(string $bytes, string $name): Response
    {
        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
