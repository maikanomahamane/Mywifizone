import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import 'sales_providers.dart';

/// Agents (vendeurs) du propriétaire : création, activation / désactivation.
class AgentsPage extends ConsumerWidget {
  const AgentsPage({super.key});

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final t = AppLocalizations.of(context)!;
    final name = TextEditingController();
    final contact = TextEditingController();
    final password = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(t.agentAdd),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name, decoration: InputDecoration(labelText: t.agentName)),
            TextField(controller: contact, decoration: InputDecoration(labelText: t.agentPhoneOrEmail), keyboardType: TextInputType.emailAddress),
            TextField(controller: password, decoration: InputDecoration(labelText: t.passwordLabel), obscureText: true),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(t.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t.create)),
        ],
      ),
    );
    final n = name.text.trim();
    final c = contact.text.trim();
    final p = password.text;
    name.dispose();
    contact.dispose();
    password.dispose();
    if (ok != true || !context.mounted) return;
    if (n.isEmpty || c.isEmpty || p.length < 8) return showMessage(context, t.errValidation);
    try {
      await ref.read(apiClientProvider).post('/agents', body: {
        'name': n,
        c.contains('@') ? 'email' : 'phone': c,
        'password': p,
      });
      ref.invalidate(agentsProvider);
    } catch (e) {
      if (context.mounted) showMessage(context, errorText(t, e));
    }
  }

  Future<void> _toggle(BuildContext context, WidgetRef ref, Map<String, dynamic> agent) async {
    final t = AppLocalizations.of(context)!;
    final next = agent['status'] == 'ACTIVE' ? 'DISABLED' : 'ACTIVE';
    try {
      await ref.read(apiClientProvider).put('/agents/${agent['id']}', body: {'status': next});
      ref.invalidate(agentsProvider);
    } catch (e) {
      if (context.mounted) showMessage(context, errorText(t, e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final agents = ref.watch(agentsProvider);

    return Scaffold(
      appBar: AppBar(title: Text(t.agentsTitle)),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _add(context, ref),
        icon: const Icon(Icons.person_add_alt),
        label: Text(t.agentAdd),
      ),
      body: agents.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(agentsProvider)),
        data: (list) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(agentsProvider),
          child: list.isEmpty
              ? ListView(children: [const SizedBox(height: 80), EmptyView(t.agentsEmpty)])
              : ListView.separated(
                  padding: const EdgeInsets.only(bottom: 88),
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (_, i) {
                    final a = list[i];
                    final active = a['status'] == 'ACTIVE';
                    return ListTile(
                      title: Text(a['name'] as String),
                      subtitle: Text((a['email'] ?? a['phone'] ?? '').toString()),
                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                        StatusChip(label: active ? t.agentActive : t.agentDisabled, color: active ? Colors.green : Colors.grey),
                        Switch(value: active, onChanged: (_) => _toggle(context, ref, a)),
                      ]),
                    );
                  },
                ),
        ),
      ),
    );
  }
}
