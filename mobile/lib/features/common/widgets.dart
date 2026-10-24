import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../design_system/app_colors.dart';
import '../../localization/app_localizations.dart';

/// Message d'erreur localisé pour un code d'erreur stable du backend (errors[].code).
String errorText(AppLocalizations t, Object error) {
  final code = error is ApiException ? error.code : 'server_error';
  return switch (code) {
    'plan_limit_reached' => t.errPlanLimit,
    'subscription_inactive' => t.errSubscriptionInactive,
    'router_not_enrolled' => t.errRouterNotEnrolled,
    'feature_unavailable' => t.errFeatureUnavailable,
    'forbidden' => t.errForbidden,
    'validation_failed' => t.errValidation,
    'profile_in_use' => t.errProfileInUse,
    'voucher_not_revocable' => t.errVoucherNotRevocable,
    'no_voucher_available' => t.errNoVoucher,
    'voucher_not_sellable' => t.errNoVoucher,
    'cash_session_required' => t.errCashSessionRequired,
    'cash_session_already_open' => t.errCashSessionOpen,
    'insufficient_cash' => t.errInsufficientCash,
    'duplicate_reference' => t.errDuplicateRef,
    'cash_session_closed' => t.errCashClosed,
    'no_printable_tickets' => t.errNoPrintable,
    'too_many_tickets' => t.errTooManyTickets,
    'export_too_large' => t.errExportTooLarge,
    'pdf_engine_unavailable' => t.errPdfUnavailable,
    'logo_too_large' => t.errLogoInvalid,
    'logo_invalid' => t.errLogoInvalid,
    'logo_unsupported_type' => t.errLogoInvalid,
    'logo_dimensions' => t.errLogoInvalid,
    'rate_unavailable' => t.errRateUnavailable,
    'rate_invalid' => t.errValidation,
    'peg_rate_fixed' => t.errPegFixed,
    'currency_unavailable' => t.errValidation,
    'rate_overflow' => t.errValidation,
    'portal_unavailable' => t.errPortalUnavailable,
    'network_error' => t.errorNetwork,
    _ => t.errorGeneric,
  };
}

void showMessage(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));
}

/// Erreur + bouton « Réessayer ».
class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.error, required this.onRetry});
  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(errorText(t, error), textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: onRetry, child: Text(t.retry)),
          ],
        ),
      ),
    );
  }
}

class EmptyView extends StatelessWidget {
  const EmptyView(this.message, {super.key});
  final String message;

  @override
  Widget build(BuildContext context) =>
      Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(message, textAlign: TextAlign.center)));
}

/// Pastille de statut colorée (la couleur n'est jamais le seul indicateur : le libellé est toujours affiché).
class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.label, required this.color});
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}

Color voucherStatusColor(String status) => switch (status) {
      'AVAILABLE' || 'VIP' => AppColors.success,
      'SOLD' || 'ACTIVE' => AppColors.primary,
      'GENERATED' => AppColors.warning,
      _ => Colors.grey,
    };

String voucherStatusLabel(AppLocalizations t, String status) => switch (status) {
      'GENERATED' => t.voucherStatusGenerated,
      'AVAILABLE' => t.voucherStatusAvailable,
      'SOLD' => t.voucherStatusSold,
      'ACTIVE' => t.voucherStatusActive,
      'USED' => t.voucherStatusUsed,
      'EXPIRED' => t.voucherStatusExpired,
      'REVOKED' => t.voucherStatusRevoked,
      'VIP' => t.voucherStatusVip,
      _ => status,
    };

String routerStatusLabel(AppLocalizations t, String status) => switch (status) {
      'ONLINE' => t.routerStatusOnline,
      'OFFLINE' => t.routerStatusOffline,
      _ => t.routerStatusPending,
    };

Color routerStatusColor(String status) => switch (status) {
      'ONLINE' => AppColors.success,
      'OFFLINE' => AppColors.error,
      _ => AppColors.warning,
    };
