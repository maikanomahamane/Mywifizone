import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import '../sales/sales_providers.dart';

class ProfilesPage extends ConsumerWidget {
  const ProfilesPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final profiles = ref.watch(profilesProvider);

    return Scaffold(
      appBar: AppBar(title: Text(t.profilesTitle)),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final created = await showModalBottomSheet<bool>(
            context: context,
            isScrollControlled: true,
            builder: (_) => const _ProfileForm(),
          );
          if (created == true) ref.invalidate(profilesProvider);
        },
        icon: const Icon(Icons.add),
        label: Text(t.profileAdd),
      ),
      body: profiles.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(profilesProvider)),
        data: (list) => list.isEmpty
            ? EmptyView(t.profilesEmpty)
            : ListView.separated(
                padding: const EdgeInsets.only(bottom: 88),
                itemCount: list.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (_, i) {
                  final p = list[i];
                  final minutes = p['duration_minutes'] as int?;
                  final details = [
                    formatMoney(p['price'] as num, p['currency'] as String),
                    minutes == null ? '∞' : '$minutes min',
                    if (p['rate_limit'] != null) p['rate_limit'],
                  ].join(' · ');
                  return ListTile(
                    title: Text(p['name'] as String),
                    subtitle: Text(details),
                    trailing: p['kind'] == 'VIP'
                        ? StatusChip(label: t.voucherStatusVip, color: voucherStatusColor('VIP'))
                        : (can(ref, 'profiles.manage') && (p['price'] as num) > 0)
                            ? Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Switch(
                                    value: p['portal_visible'] == true,
                                    onChanged: (v) async {
                                      try {
                                        await ref.read(apiClientProvider).put('/profiles/${p['id']}', body: {'portal_visible': v});
                                        ref.invalidate(profilesProvider);
                                      } catch (e) {
                                        if (context.mounted) showMessage(context, errorText(t, e));
                                      }
                                    },
                                  ),
                                  Text(t.portalOffer, style: Theme.of(context).textTheme.labelSmall),
                                ],
                              )
                            : null,
                  );
                },
              ),
      ),
    );
  }
}

class _ProfileForm extends ConsumerStatefulWidget {
  const _ProfileForm();

  @override
  ConsumerState<_ProfileForm> createState() => _ProfileFormState();
}

class _ProfileFormState extends ConsumerState<_ProfileForm> {
  final _name = TextEditingController();
  final _price = TextEditingController(text: '200');
  final _duration = TextEditingController(text: '60');
  final _rate = TextEditingController(text: '2M/2M');
  final _devices = TextEditingController(text: '1');
  bool _vip = false;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [_name, _price, _duration, _rate, _devices]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final t = AppLocalizations.of(context)!;
    final currency = (ref.read(currentUserProvider)?['tenant']?['currency'] as String?) ?? 'XOF';
    final price = parseMajorToMinor(_price.text, currency);
    final devices = int.tryParse(_devices.text.trim());
    final duration = _duration.text.trim().isEmpty ? null : int.tryParse(_duration.text.trim());
    if (_name.text.trim().isEmpty || price == null || devices == null || (_duration.text.trim().isNotEmpty && duration == null)) {
      showMessage(context, t.errValidation);
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(apiClientProvider).post('/profiles', body: {
        'name': _name.text.trim(),
        'kind': _vip ? 'VIP' : 'STANDARD',
        'price': price, // entier en unités mineures : jamais de flottant pour l'argent
        'currency': currency,
        'duration_minutes': duration,
        'rate_limit': _rate.text.trim().isEmpty ? null : _rate.text.trim(),
        'device_limit': devices,
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        showMessage(context, errorText(t, e));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(t.profileAdd, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 12),
            TextField(controller: _name, decoration: InputDecoration(labelText: t.profileName)),
            const SizedBox(height: 12),
            TextField(
              controller: _price,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: t.profilePrice,
                suffixText: (ref.read(currentUserProvider)?['tenant']?['currency'] as String?) ?? 'XOF',
              ),
            ),
            const SizedBox(height: 12),
            TextField(controller: _duration, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: t.profileDuration)),
            const SizedBox(height: 12),
            TextField(controller: _rate, decoration: InputDecoration(labelText: t.profileRate)),
            const SizedBox(height: 12),
            TextField(controller: _devices, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: t.profileDevices)),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(t.profileVip), value: _vip, onChanged: (v) => setState(() => _vip = v)),
            const SizedBox(height: 8),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Text(t.create),
            ),
          ],
        ),
      ),
    );
  }
}
