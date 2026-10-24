import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';

class GenerateBatchSheet extends ConsumerStatefulWidget {
  const GenerateBatchSheet({super.key});

  @override
  ConsumerState<GenerateBatchSheet> createState() => _GenerateBatchSheetState();
}

class _GenerateBatchSheetState extends ConsumerState<GenerateBatchSheet> {
  final _quantity = TextEditingController(text: '10');
  final _label = TextEditingController();
  String? _routerId;
  String? _profileId;
  bool _busy = false;

  @override
  void dispose() {
    _quantity.dispose();
    _label.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final t = AppLocalizations.of(context)!;
    final quantity = int.tryParse(_quantity.text.trim());
    if (_routerId == null || _profileId == null || quantity == null || quantity < 1) {
      showMessage(context, t.errValidation);
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(apiClientProvider).post('/voucher-batches', body: {
        'router_id': _routerId,
        'profile_id': _profileId,
        'quantity': quantity,
        if (_label.text.trim().isNotEmpty) 'label': _label.text.trim(),
      });
      if (!mounted) return;
      showMessage(context, t.batchQueued);
      Navigator.pop(context, true);
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
    final routers = ref.watch(routersProvider);
    final profiles = ref.watch(profilesProvider);

    // Seuls les routeurs déjà enrôlés peuvent recevoir des tickets.
    final enrolled = routers.valueOrNull?.where((r) => r['agent_enrolled'] == true).toList() ?? const <Json>[];
    final activeProfiles = profiles.valueOrNull?.where((p) => p['is_active'] == true).toList() ?? const <Json>[];

    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(t.ticketsGenerate, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 16),
            if (routers.hasValue && enrolled.isEmpty) Text(t.noRouter),
            if (profiles.hasValue && activeProfiles.isEmpty) Text(t.noProfile),
            DropdownButtonFormField<String>(
              value: _routerId,
              isExpanded: true,
              decoration: InputDecoration(labelText: t.batchRouter),
              items: [for (final r in enrolled) DropdownMenuItem(value: r['id'] as String, child: Text(r['name'] as String))],
              onChanged: (v) => setState(() => _routerId = v),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: _profileId,
              isExpanded: true,
              decoration: InputDecoration(labelText: t.batchProfile),
              items: [
                for (final p in activeProfiles)
                  DropdownMenuItem(value: p['id'] as String, child: Text('${p['name']} · ${formatMoney(p['price'] as num, p['currency'] as String)}')),
              ],
              onChanged: (v) => setState(() => _profileId = v),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _quantity,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: t.batchQuantity),
            ),
            const SizedBox(height: 12),
            TextField(controller: _label, decoration: InputDecoration(labelText: t.batchLabel)),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Text(t.ticketsGenerate),
            ),
          ],
        ),
      ),
    );
  }
}
