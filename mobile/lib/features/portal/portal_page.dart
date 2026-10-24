import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';

final portalSettingsProvider = FutureProvider.autoDispose<Json>((ref) async => (await ref.watch(apiClientProvider).get('/portal-settings')).map);

final portalOrdersProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/portal-orders', query: {'per_page': 50});
  return res.list;
});

Color _orderColor(String status) => switch (status) {
      'FULFILLED' => Colors.green,
      'NEEDS_ATTENTION' => Colors.red,
      'PENDING_PAYMENT' => Colors.orange,
      _ => Colors.grey,
    };

String _orderLabel(AppLocalizations t, String status) => switch (status) {
      'FULFILLED' => t.orderFulfilled,
      'NEEDS_ATTENTION' => t.orderNeedsAttention,
      'PENDING_PAYMENT' => t.orderPending,
      'FAILED' => t.orderFailed,
      _ => t.orderExpired,
    };

/// Portail captif : activation, adresse à placer dans la page de connexion du HotSpot, commandes d'achat direct.
/// Les paiements réels ne sont pas branchés : seul le fournisseur de test existe (voir ADR 0009).
class PortalPage extends ConsumerWidget {
  const PortalPage({super.key});

  Future<void> _update(BuildContext context, WidgetRef ref, Map<String, dynamic> body) async {
    final t = AppLocalizations.of(context)!;
    try {
      await ref.read(apiClientProvider).put('/portal-settings', body: body);
      ref.invalidate(portalSettingsProvider);
    } catch (e) {
      if (context.mounted) showMessage(context, errorText(t, e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final settings = ref.watch(portalSettingsProvider);
    final orders = ref.watch(portalOrdersProvider);

    return Scaffold(
      appBar: AppBar(title: Text(t.portalTitle)),
      body: settings.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(portalSettingsProvider)),
        data: (s) {
          final url = s['page_url'] as String?;
          return RefreshIndicator(
            onRefresh: () async {
              ref.invalidate(portalSettingsProvider);
              ref.invalidate(portalOrdersProvider);
            },
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(t.portalEnable),
                  subtitle: s['feature_available'] == true ? null : Text(t.errFeatureUnavailable),
                  value: s['enabled'] == true,
                  onChanged: (v) => _update(context, ref, {'enabled': v}),
                ),
                if (url != null) ...[
                  const SizedBox(height: 8),
                  Text(t.portalUrl, style: Theme.of(context).textTheme.labelLarge),
                  SelectableText(url),
                  Row(
                    children: [
                      TextButton.icon(
                        icon: const Icon(Icons.copy),
                        label: Text(t.copy),
                        onPressed: () async {
                          await Clipboard.setData(ClipboardData(text: url));
                          if (context.mounted) showMessage(context, t.copied);
                        },
                      ),
                      TextButton.icon(
                        icon: const Icon(Icons.refresh),
                        label: Text(t.portalRegenerate),
                        onPressed: () => _update(context, ref, {'regenerate_slug': true}),
                      ),
                    ],
                  ),
                  Text(t.portalSetupHint, style: Theme.of(context).textTheme.bodySmall),
                ],
                const Divider(height: 32),
                Text(t.portalOrders, style: Theme.of(context).textTheme.titleMedium),
                orders.when(
                  loading: () => const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator())),
                  error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(portalOrdersProvider)),
                  data: (list) => list.isEmpty
                      ? Padding(padding: const EdgeInsets.all(16), child: Text(t.portalNoOrders))
                      : Column(
                          children: [
                            for (final o in list)
                              ListTile(
                                contentPadding: EdgeInsets.zero,
                                title: Text('${o['profile_name'] ?? ''} · ${formatMoney(o['amount'] as num, o['currency'] as String)}'),
                                subtitle: Text('${shortDateTime(o['created_at'] as String?)}${o['customer_phone'] != null ? ' · ${o['customer_phone']}' : ''}'),
                                trailing: StatusChip(label: _orderLabel(t, o['status'] as String), color: _orderColor(o['status'] as String)),
                              ),
                          ],
                        ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
