<?php

namespace App\Domain\Documents;

/** Identité visuelle d'un document, DÉJÀ validée (couleur #RRGGBB, logo = PNG ré-encodé). Les textes restent bruts : Html::e() les échappe. */
final class Brand
{
    public readonly string $color;

    public readonly ?string $portalUrl;

    public readonly ?string $logoPngBase64;

    public function __construct(
        public readonly string $name,
        public readonly ?string $phone = null,
        public readonly ?string $slogan = null,
        public readonly ?string $terms = null,
        ?string $color = null,
        ?string $portalUrl = null,
        ?string $logoPngBase64 = null,
    ) {
        // Défense en profondeur : même si l'appelant oublie de valider, une valeur douteuse ne passe jamais telle quelle.
        $this->color = HexColor::normalize($color);
        $this->portalUrl = TicketQr::isValidPortalUrl($portalUrl) ? $portalUrl : null;
        $this->logoPngBase64 = $logoPngBase64 !== null && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $logoPngBase64) === 1 ? $logoPngBase64 : null;
    }

    public function textColor(): string
    {
        return HexColor::contrastText($this->color);
    }

    public function logoDataUri(): ?string
    {
        return $this->logoPngBase64 === null ? null : 'data:image/png;base64,'.$this->logoPngBase64;
    }
}
