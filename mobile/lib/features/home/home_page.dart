import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../network/network_providers.dart';
import '../sales/sales_providers.dart';

/// Tableau de bord Phase 3 : quelques compteurs réels (routeurs, clients, tickets). Les graphiques arrivent avec les rapports.
class HomePage extends ConsumerWidget {
  const HomePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final user = ref.watch(currentUserProvider);
    final routers = ref.watch(routersProvider);
    final sessions = ref.watch(sessionsProvider);
    final available = ref.watch(voucherCountProvider('AVAILABLE'));
    final subscription = ref.watch(subscriptionProvider);
    final dashboard = ref.watch(dashboardProvider);

    Future<void> refresh() async {
      ref.invalidate(routersProvider);
      ref.invalidate(sessionsProvider);
      ref.invalidate(voucherCountProvider('AVAILABLE'));
      ref.invalidate(subscriptionProvider);
      ref.invalidate(dashboardProvider);
    }

    String count(AsyncValue<int> v) => v.when(data: (n) => '$n', loading: () => '…', error: (_, __) => '–');

    final online = routers.whenData((l) => l.where((r) => r['status'] == 'ONLINE').length);
    final clients = sessions.whenData((l) => l.length);
    final subText = subscription.when(
      data: (s) {
        final sub = s['subscription'];
        return sub is Map ? '${sub['plan'] ?? ''} · ${sub['status'] ?? ''}' : '–';
      },
      loading: () => '…',
      error: (_, __) => '–',
    );

    final revenue = dashboard.when(
      data: (d) {
        final list = (d['revenue_today'] as List?) ?? const [];
        return list.isEmpty ? '0' : list.map((r) => formatMoney(r['total'] as num, r['currency'] as String)).join(' · ');
      },
      loading: () => '…',
      error: (_, __) => '–',
    );
    final activeTickets = dashboard.when(
      data: (d) => '${(d['tickets'] as Map?)?['active'] ?? 0}',
      loading: () => '…',
      error: (_, __) => '–',
    );

    return Scaffold(
      appBar: AppBar(title: Text(t.homeTitle)),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            Text(user?['name'] as String? ?? '', style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 16),
            Wrap(
              spacing: 12,
              runSpacing: 12,
              children: [
                _StatCard(icon: Icons.router, label: t.dashRoutersOnline, value: count(online)),
                _StatCard(icon: Icons.people_outline, label: t.dashActiveSessions, value: count(clients)),
                _StatCard(icon: Icons.confirmation_number_outlined, label: t.dashVouchersAvailable, value: count(available)),
                _StatCard(icon: Icons.payments_outlined, label: t.dashRevenueToday, value: revenue),
                _StatCard(icon: Icons.wifi_tethering, label: t.dashTicketsActive, value: activeTickets),
                _StatCard(icon: Icons.workspace_premium_outlined, label: t.dashSubscription, value: subText),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.icon, required this.label, required this.value});
  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final width = (MediaQuery.of(context).size.width - 16 * 2 - 12) / 2;
    return SizedBox(
      width: width,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon),
              const SizedBox(height: 8),
              Text(value, style: Theme.of(context).textTheme.headlineSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
              Text(label, style: Theme.of(context).textTheme.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}
