# ADR 0007 — Documents : PDF, QR, CSV, personnalisation (Phase 5)

Statut : accepté — **rendu PDF non vérifié visuellement** (voir « Limites »).

## Décisions

1. **PDF côté serveur avec mPDF** (`composer require mpdf/mpdf`) derrière l'interface `PdfEngine`. Raison : texte arabe (droite à gauche, ligatures), lettres hausa (ɓ ɗ ƙ ʼ) et QR natifs (`<barcode type="QR">`) sans service externe. Si la classe est absente : `503 pdf_engine_unavailable`.
2. **Couche de domaine pure** (`App\Domain\Documents`) : textes fr/en/ar/ha, montants (unités mineures), durées, mise en page, constructeurs HTML, CSV, couleur, logo. Testable sans Laravel ni mPDF.
3. **Contenu du QR** : le code du ticket seul, ou, si le tenant a configuré `portal_url` (http/https, ≤ 200 car., sans espace ni guillemet), `portal_url?username=CODE&password=CODE`. Aucun secret serveur. L'URL n'est jamais appelée par le serveur (pas de SSRF). Le comportement du lien sur un vrai portail MikroTik reste **à valider sur routeur**.
4. **Sécurité PDF** : toute donnée utilisateur passe par `Html::e` ; la couleur n'est acceptée que `#RRGGBB` ; la seule balise `<img>` produite est le logo en data-URI ; les codes ne sont jamais journalisés (audit : nombre, format, QR, langue).
5. **Logo** : PNG/JPEG ≤ 512 Kio, 16–4000 px, décodé par GD et ré-encodé en PNG ≤ 512 px (supprime métadonnées et données ajoutées) ; stocké sur disque privé sous un nom uuid aléatoire ; SVG refusé.
6. **CSV** : séparateur `;`, UTF-8 + BOM (Excel), montants entiers en unités mineures, cellules texte commençant par `= + - @ TAB CR` préfixées d'une apostrophe (injection de formules).
7. **Droits et formules** : PDF des tickets = permission `tickets.export` (propriétaire, agent) ; QR = fonctionnalité `qr` (PRO/BUSINESS) ; CSV = fonctionnalité `reports` + `reports.view` ; personnalisation = `tenant.manage`, écriture bloquée si abonnement inactif.
8. **Limites** : 500 tickets/PDF, 50 000 lignes/CSV, 366 jours, 20 requêtes/min/utilisateur.
9. **Factures** : sans mentions fiscales (numérotation non séquentielle, voir ADR 0003). Une facture légale demandera un compteur et les mentions locales.

## Limites connues

- mPDF n'a pas pu être installé ni exécuté dans l'environnement de développement (registres bloqués). `PdfRenderingTest` génère de vrais PDF en CI dans `storage/app/sample-pdfs` (artefact `sample-pdfs`) : **à ouvrir et contrôler à l'œil** (arabe, hausa, taille du QR, logo, coupe des cartes).
- Traductions arabe et hausa à faire relire par des locuteurs.
- Pas de formats Excel natifs : CSV uniquement.
