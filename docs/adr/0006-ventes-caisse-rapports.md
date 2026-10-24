# ADR 0006 — Ventes, caisse, rapports, agents (Phase 4)

Statut : accepté (Phase 4). À revoir quand les paiements vérifiés (Phase 7) arriveront.

## Contexte
Le propriétaire (ou son agent) vend des tickets WiFi du stock, encaisse, suit sa caisse et lit ses recettes depuis un téléphone, souvent avec un réseau instable.

## Décisions

1. **Une vente = un voucher du stock.** Le voucher passe `AVAILABLE -> SOLD` dans la même transaction que la création de la vente et, pour les espèces, que l'écriture de caisse. Une étape qui échoue annule tout. Seuls les vouchers `AVAILABLE` **et** `SYNCED` (confirmés par le routeur) sont vendables ; un voucher non confirmé n'est jamais remis à un client.
2. **Montant = instantané du prix du voucher** (lui-même copié du profil à la génération) : modifier un profil ne réécrit pas l'historique. Entiers en unités mineures + devise, jamais de décimaux.
3. **Idempotence par `client_ref`** (unique par tenant) : l'application génère la clé à l'ouverture de l'écran de vente ; un rejeu après perte de réponse renvoie la vente d'origine (200) sans second voucher ni second encaissement. Le code du voucher n'est renvoyé qu'à la création (et au rejeu par le vendeur d'origine ou un propriétaire).
4. **Référence de paiement = saisie libre, non vérifiée** (`provider_transaction_id`), unique par `(tenant, moyen de paiement, référence)` pour éviter la double saisie. Ce n'est pas une preuve de paiement : les paiements vérifiés passeront par `PaymentProviderInterface` (Phase 7). Aucune API d'opérateur n'est supposée.
5. **Caisse : journal append-only.** `cash_transactions` ne se modifie ni ne se supprime ; une erreur se corrige par une écriture `ADJUSTMENT`. Le montant est signé (+ entrée, − sortie) ; le type décide du sens (`CashType::signed`). Le solde est la somme du journal.
6. **Une seule session ouverte par caisse et par utilisateur**, garanti par la base (colonnes `open_key` / `open_user_key` uniques, remises à NULL à la clôture), pas par un test applicatif sujet aux courses.
7. **Clôture** : solde attendu (journal), solde compté (saisi), écart (compté − attendu) figés dans la session et dans une écriture `CLOSE`.
8. **Annulation d'une vente** (propriétaire, `sales.void`) : statut `VOIDED`, jamais de suppression ; les espèces génèrent une écriture inverse dans la session d'origine si elle est ouverte, sinon dans la session ouverte de celui qui annule ; le voucher est révoqué sauf demande contraire (sans effet s'il est déjà utilisé).
9. **Vendre en espèces sans session de caisse** est permis par défaut (`WIFIZONE_REQUIRE_CASH_SESSION=false`) pour ne pas bloquer un vendeur ; la vente n'est alors rattachée à aucune session. Mettre `true` impose l'ouverture d'une session.
10. **Rapports** : un seul `GROUP BY` sur `(tenant, jour local, devise[, dimension])` appuyé par des index ; le regroupement semaine/mois/année se fait en PHP sur ce résultat borné (plage max 366 jours en journalier, 1830 sinon). Les ventes annulées sont exclues. **Jamais de somme entre devises** : les totaux sont par devise. Le « jour » est le jour local du tenant (`tenants.timezone`, défaut `Africa/Niamey`), figé dans `sales.sold_on` à la vente.
11. **Formules** : journalier = `basic_stats` (toutes), hebdo/mensuel/annuel = `reports` (PRO, BUSINESS), création d'une caisse = `cash`.
12. **Agents** : utilisateurs de rôle `AGENT` du tenant, créés par le propriétaire (`agents.manage`), limités par `max_agents`. Un agent ne voit que **ses** ventes et **ses** sessions de caisse ; `sales.view_all`, `sales.void`, `cash.manage`, `reports.view` sont réservés au propriétaire. Désactiver un agent ou changer son mot de passe révoque ses jetons. Le propriétaire n'est jamais modifiable par cette API.

## Conséquences / limites connues
- Le fuseau du tenant n'a pas d'écran de réglage (colonne seule) ; changer le fuseau ne recalcule pas l'historique.
- Pas de remise, de commission d'agent, de multi-devise dans une même caisse, ni de reçu PDF/QR (Phase 5).
- Les rapports par agent/profil/site existent côté API (`group_by`) ; l'application mobile n'affiche que le regroupement par période.
- L'affichage mobile suppose 2 décimales hors devises sans subdivision connues (XOF, XAF, GNF, RWF, UGX, JPY, KRW).
