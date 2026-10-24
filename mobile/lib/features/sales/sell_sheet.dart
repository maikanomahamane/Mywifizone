import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';

String paymentLabel(AppLocalizations t, String method) => switch (method) {
      'CASH' => t.payCash,
      'MOBILE_MONEY' => t.payMobileMoney,
      'BANK' => t.payBank,
      _ => t.payOther,
    };

const paymentMethods = ['CASH', 'MOBILE_MONEY', 'BANK', 'OTHER'];

/// Vente d'un ticket du stock. `client_ref` est généré à l'ouverture de la feuille : si la réponse se perd
/// (réseau instable) et que l'agent réessaie, le serveur renvoie la même vente au lieu d'en créer une seconde.
/// Se ferme avec la vente (qui contient le code du ticket).
class SellSheet extends ConsumerStatefulWidget {
  const SellSheet({super.key});

  @override
  ConsumerState<SellSheet> createState() => _SellSheetState();
}

class _SellSheetState extends ConsumerState<SellSheet> {
  final _reference = TextEditingController();
  late final String _clientRef = 'app-${DateTime.now().microsecondsSinceEpoch}-${Random().nextInt(1 << 30)}';
  String? _routerId;
  String? _profileId;
  String _method = 'CASH';
  bool _busy = false;

  @override
  void dispose() {
    _reference.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final t = AppLocalizations.of(context)!;
    if (_routerId == null || _profileId == null) {
      showMessage(context, t.errValidation);
      return;
    }
    setState(() => _busy = true);
    try {
      final reference = _reference.text.trim();
      final res = await ref.read(apiClientProvider).post('/sales', body: {
        'router_id': _routerId,
        'profile_id': _profileId,
        'payment_method': _method,
        'client_ref': _clientRef,
        if (reference.isNotEmpty && _method != 'CASH') 'provider_transaction_id': reference,
      });
      if (mounted) Navigator.pop(context, res.map);
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
    final routers = ref.watch(routersProvider).valueOrNull ?? const <Json>[];
    final profiles = (ref.watch(profilesProvider).valueOrNull ?? const <Json>[])
        .where((p) => p['is_active'] == true && p['kind'] != 'VIP')
        .toList();

    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(t.sellTitle, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: _routerId,
              isExpanded: true,
              decoration: InputDecoration(labelText: t.sellRouter),
              items: [for (final r in routers) DropdownMenuItem(value: r['id'] as String, child: Text(r['name'] as String))],
              onChanged: (v) => setState(() => _routerId = v),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: _profileId,
              isExpanded: true,
              decoration: InputDecoration(labelText: t.sellProfile),
              items: [
                for (final p in profiles)
                  DropdownMenuItem(
                    value: p['id'] as String,
                    child: Text('${p['name']} · ${formatMoney(p['price'] as num, p['currency'] as String)}'),
                  ),
              ],
              onChanged: (v) => setState(() => _profileId = v),
            ),
            const SizedBox(height: 12),
            Text(t.sellPayment, style: Theme.of(context).textTheme.labelLarge),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              children: [
                for (final m in paymentMethods)
                  ChoiceChip(label: Text(paymentLabel(t, m)), selected: _method == m, onSelected: (_) => setState(() => _method = m)),
              ],
            ),
            if (_method != 'CASH') ...[
              const SizedBox(height: 12),
              TextField(controller: _reference, decoration: InputDecoration(labelText: t.sellReference)),
            ],
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Text(t.sellButton),
            ),
          ],
        ),
      ),
    );
  }
}
