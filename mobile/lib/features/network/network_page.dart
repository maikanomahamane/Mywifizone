import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import 'enrollment_dialog.dart';
import 'network_providers.dart';
import 'router_form.dart';

/// Onglet Réseau : routeurs (état, clients, enrôlement) et clients connectés.
class NetworkPage extends StatelessWidget {
  const NetworkPage({super.key});

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(t.networkTitle),
          bottom: TabBar(tabs: [Tab(text: t.navNetwork), Tab(text: t.sessionsTitle)]),
        ),
        body: const TabBarView(children: [_RoutersTab(), _SessionsTab()]),
      ),
    );
  }
}

class _RoutersTab extends ConsumerWidget {
  const _RoutersTab();

  Future<void> _action(BuildContext context, WidgetRef ref, Future<void> Function() run, String okMessage) async {
    final t = AppLocalizations.of(context)!;
    try {
      await run();
      ref.invalidate(routersProvider);
      if (context.mounted) showMessage(context, okMessage);
    } catch (e) {
      if (context.mounted) showMessage(context, errorText(t, e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final routers = ref.watch(routersProvider);

    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final created = await showModalBottomSheet<Json>(
            context: context,
            isScrollControlled: true,
            builder: (_) => const RouterForm(),
          );
          if (created == null || !context.mounted) return;
          ref.invalidate(routersProvider);
          await showEnrollmentDialog(context, created);
        },
        icon: const Icon(Icons.add),
        label: Text(t.routerAdd),
      ),
      body: routers.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(routersProvider)),
        data: (list) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(routersProvider),
          child: list.isEmpty
              ? ListView(children: [const SizedBox(height: 80), EmptyView(t.routersEmpty)])
              : ListView.separated(
                  padding: const EdgeInsets.only(bottom: 88),
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (_, i) {
                    final r = list[i];
                    final status = r['status'] as String;
                    final model = r['model'] as String?;
                    return ListTile(
                      leading: const Icon(Icons.router),
                      title: Text(r['name'] as String),
                      subtitle: Text([
                        if (model != null) model,
                        '${r['clients_count']} ${t.sessionsTitle.toLowerCase()}',
                      ].join(' · ')),
                      trailing: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          StatusChip(label: routerStatusLabel(t, status), color: routerStatusColor(status)),
                          PopupMenuButton<String>(
                            onSelected: (v) async {
                              final api = ref.read(apiClientProvider);
                              if (v == 'sync') {
                                await _action(context, ref, () async => api.post('/routers/${r['id']}/sync'), t.syncQueued);
                              } else if (v == 'rotate') {
                                try {
                                  final res = await api.post('/routers/${r['id']}/rotate-agent-token');
                                  ref.invalidate(routersProvider);
                                  if (context.mounted) await showEnrollmentDialog(context, res.map);
                                } catch (e) {
                                  if (context.mounted) showMessage(context, errorText(t, e));
                                }
                              }
                            },
                            itemBuilder: (_) => [
                              if (r['agent_enrolled'] == true) PopupMenuItem(value: 'sync', child: Text(t.routerSync)),
                              PopupMenuItem(value: 'rotate', child: Text(t.routerRotate)),
                            ],
                          ),
                        ],
                      ),
                    );
                  },
                ),
        ),
      ),
    );
  }
}

class _SessionsTab extends ConsumerWidget {
  const _SessionsTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final sessions = ref.watch(sessionsProvider);

    return sessions.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(sessionsProvider)),
      data: (list) => RefreshIndicator(
        onRefresh: () async => ref.invalidate(sessionsProvider),
        child: list.isEmpty
            ? ListView(children: [const SizedBox(height: 80), EmptyView(t.sessionsEmpty)])
            : ListView.separated(
                itemCount: list.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (_, i) {
                  final s = list[i];
                  final minutes = ((s['uptime_seconds'] as num?) ?? 0) ~/ 60;
                  return ListTile(
                    title: Text(s['username'] as String, style: const TextStyle(fontFamily: 'monospace', fontWeight: FontWeight.w700)),
                    subtitle: Text('${s['ip'] ?? ''} · ${s['mac'] ?? ''} · $minutes min'),
                    trailing: IconButton(
                      tooltip: t.disconnect,
                      icon: const Icon(Icons.link_off),
                      onPressed: () async {
                        try {
                          await ref.read(apiClientProvider).post('/sessions/${s['id']}/disconnect');
                          if (context.mounted) showMessage(context, t.disconnectQueued);
                        } catch (e) {
                          if (context.mounted) showMessage(context, errorText(t, e));
                        }
                      },
                    ),
                  );
                },
              ),
      ),
    );
  }
}
