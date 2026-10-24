<?php

namespace App\Services;

use App\Domain\Documents\Brand;
use App\Domain\Documents\HexColor;
use App\Domain\Documents\LogoProcessor;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Support\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Personnalisation des documents du tenant courant (contexte tenant requis). */
final class BrandingService
{
    public function current(): TenantBranding
    {
        return TenantBranding::first() ?? new TenantBranding;
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): TenantBranding
    {
        $branding = TenantBranding::first() ?? new TenantBranding;
        $branding->fill($data);
        $branding->save();

        return $branding;
    }

    public function storeLogo(UploadedFile $file): TenantBranding
    {
        $bytes = (string) @file_get_contents($file->getRealPath());

        try {
            $processed = LogoProcessor::process($bytes);
        } catch (InvalidArgumentException $e) {
            throw new ApiException($e->getMessage(), 'Logo refusé : '.$e->getMessage(), 422);
        }

        $branding = TenantBranding::first() ?? new TenantBranding;
        $old = $branding->logo_path;

        // Nom aléatoire, disque privé, extension imposée : le nom du fichier envoyé n'est jamais utilisé.
        $path = 'branding/'.Str::uuid().'.png';
        Storage::disk(config('documents.logo_disk'))->put($path, $processed['png']);
        $branding->logo_path = $path;
        $branding->save();

        if ($old) {
            Storage::disk(config('documents.logo_disk'))->delete($old);
        }

        return $branding;
    }

    public function removeLogo(): void
    {
        $branding = TenantBranding::first();
        if ($branding?->logo_path) {
            Storage::disk(config('documents.logo_disk'))->delete($branding->logo_path);
            $branding->logo_path = null;
            $branding->save();
        }
    }

    public function logoBytes(): ?string
    {
        $path = TenantBranding::first()?->logo_path;
        $disk = Storage::disk(config('documents.logo_disk'));

        return $path && $disk->exists($path) ? $disk->get($path) : null;
    }

    /** Identité visuelle prête pour un document : valeurs de repli sûres si rien n'est configuré. */
    public function brand(Tenant $tenant): Brand
    {
        $b = TenantBranding::first();
        $logo = $this->logoBytes();

        return new Brand(
            name: $b?->display_name ?: $tenant->name,
            phone: $b?->phone,
            slogan: $b?->slogan,
            terms: $b?->terms,
            color: HexColor::normalize($b?->primary_color),
            portalUrl: $b?->portal_url,
            logoPngBase64: $logo !== null ? base64_encode($logo) : null,
        );
    }
}
