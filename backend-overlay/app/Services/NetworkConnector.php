<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterCommand;

/**
 * Abstraction de la liaison avec un routeur. Les services métier (vouchers, sessions...) n'appellent jamais RouterOS :
 * ils demandent à un connecteur d'exécuter une commande, quel que soit le mode de connexion du routeur.
 *
 * - AgentConnector : file de commandes que l'agent (connexion SORTANTE, compatible CGNAT) vient chercher. Branché.
 * - RestApiConnector : appel direct de l'API REST RouterOS (HTTPS) quand le routeur est joignable depuis le serveur. Branché.
 */
interface NetworkConnector
{
    public function mode(): string;

    /** Vrai si ce connecteur est opérationnel (configuré et utilisable). */
    public function isAvailable(): bool;

    /**
     * Demande l'exécution d'une commande sur le routeur. Retourne la commande créée (suivi du statut).
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(Router $router, string $type, array $payload, ?string $createdBy = null): RouterCommand;
}
