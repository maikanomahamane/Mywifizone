import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../documents/branding_page.dart';
import '../international/exchange_rates_page.dart';
import '../portal/external_links_page.dart';
import '../portal/portal_page.dart';
import '../international/regional_settings_page.dart';
import '../network/network_providers.dart';
import '../sales/agents_page.dart';
import '../sales/cash_page.dart';
import '../sales/reports_page.dart';
import '../sales/sales_providers.dart';

class MorePage extends ConsumerWidget {
  const MorePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final locale = ref.watch(localeProvider);
    final user = ref.watch(currentUserProvider);
    final subscription = ref.watch(subscriptionProvider);

    final sub = subscription.valueOrNull?['subscription'];
    final subLines = sub is Map
        ? [
            '${t.subscriptionPlan} : ${sub['plan'] ?? '–'}',
            '${t.subscriptionStatus} : ${sub['status'] ?? '–'}',
            if (sub['trial_ends_at'] != null) '${t.trialEndsAt} : ${(sub['trial_ends_at'] as String).substring(0, 10)}',
          ]
        : <String>[];

    return Scaffold(
      appBar: AppBar(title: Text(t.moreTitle)),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.person_outline),
            title: Text(user?['name'] as String? ?? ''),
            subtitle: Text((user?['email'] ?? user?['phone'] ?? '').toString()),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.workspace_premium_outlined),
            title: Text(t.subscriptionTitle),
            subtitle: subLines.isEmpty ? null : Text(subLines.join('\n')),
            isThreeLine: subLines.length > 2,
          ),
          if (can(ref, 'cash.view'))
            ListTile(
              leading: const Icon(Icons.account_balance_wallet_outlined),
              title: Text(t.moreCash),
              onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const CashPage())),
            ),
          if (can(ref, 'reports.view'))
            ListTile(
              leading: const Icon(Icons.bar_chart),
              title: Text(t.moreReports),
              onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const ReportsPage())),
            ),
          if (can(ref, 'tenant.manage'))
            ListTile(
              leading: const Icon(Icons.palette_outlined),
              title: Text(t.brandingTitle),
              onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const BrandingPage())),
            ),
          if (can(ref, 'tenant.manage'))
            ListTile(
              leading: const Icon(Icons.public),
              title: Text(t.regionalTitle),
              onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const RegionalSettingsPage())),
            ),
          if (can(ref, 'tenant.manage'))
            ListTile(
              leading: const Icon(Icons.currency_exchange),
              title: Text(t.ratesTitle),
              onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const ExchangeRatesPage())),
            ),
          if (can(ref, 'tenant.manage'))
            ListTile(
              leading: const Icon(Icons.wifi_tethering),
              title: Text(t.portalTitle),
              onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const PortalPage())),
            ),
          ListTile(
            leading: const Icon(Icons.apps_outlined),
            title: Text(t.servicesTitle),
            onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const ExternalLinksPage())),
          ),
          if (can(ref, 'agents.manage'))
            ListTile(
              leading: const Icon(Icons.groups_outlined),
              title: Text(t.moreAgents),
              onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const AgentsPage())),
            ),
          ListTile(
            leading: const Icon(Icons.language),
            title: Text(t.languageLabel),
            trailing: DropdownButton<String>(
              value: locale.languageCode,
              underline: const SizedBox.shrink(),
              items: const [
                DropdownMenuItem(value: 'fr', child: Text('Français')),
                DropdownMenuItem(value: 'en', child: Text('English')),
                DropdownMenuItem(value: 'ar', child: Text('العربية')),
                DropdownMenuItem(value: 'ha', child: Text('Hausa')),
              ],
              onChanged: (code) {
                if (code != null) ref.read(localeProvider.notifier).state = Locale(code);
              },
            ),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.logout),
            title: Text(t.logout),
            onTap: () async {
              await ref.read(tokenStorageProvider).clear();
              ref.read(currentUserProvider.notifier).state = null;
              if (context.mounted) context.go('/login');
            },
          ),
        ],
      ),
    );
  }
}
