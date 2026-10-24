import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import 'international_providers.dart';

/// Taux saisis par le propriétaire + parités fixes, et convertisseur INDICATIF.
/// Un taux ne modifie jamais un prix (cahier des charges §4). Fonction `multi_currency` (PRO/BUSINESS).
class ExchangeRatesPage extends ConsumerStatefulWidget {
  const ExchangeRatesPage({super.key});

  @override
  ConsumerState<ExchangeRatesPage> createState() => _ExchangeRatesPageState();
}

class _ExchangeRatesPageState extends ConsumerState<ExchangeRatesPage> {
  final _amount = TextEditingController(text: '1000');
  String? _from;
  String? _to;
  String? _result;

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  Future<void> _convert() async {
    final t = AppLocalizations.of(context)!;
    final from = _from;
    final to = _to;
    final minor = from == null ? null : parseMajorToMinor(_amount.text, from);
    if (from == null || to == null || minor == null) {
      showMessage(context, t.errValidation);
      return;
    }
    try {
      final res = (await ref.read(apiClientProvider).get('/currencies/convert', query: {'amount': minor, 'from': from, 'to': to})).map;
      if (mounted) setState(() => _result = '≈ ${formatMoney(res['amount'] as num, res['currency'] as String)} (${t.indicative}, ${res['rate']})');
    } catch (e) {
      if (mounted) showMessage(context, errorText(t, e));
    }
  }

  Future<void> _delete(Json rate) async {
    final t = AppLocalizations.of(context)!;
    try {
      await ref.read(apiClientProvider).delete('/exchange-rates/${rate['id']}');
      ref.invalidate(exchangeRatesProvider);
    } catch (e) {
      if (mounted) showMessage(context, errorText(t, e));
    }
  }

  Future<void> _add() async {
    final saved = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, builder: (_) => const _RateForm());
    if (saved == true) ref.invalidate(exchangeRatesProvider);
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    final rates = ref.watch(exchangeRatesProvider);
    final currencies = ref.watch(currenciesProvider).valueOrNull ?? const <Json>[];
    final items = [for (final c in currencies) DropdownMenuItem(value: c['code'] as String, child: Text(c['code'] as String))];

    return Scaffold(
      appBar: AppBar(title: Text(t.ratesTitle)),
      floatingActionButton: FloatingActionButton.extended(onPressed: _add, icon: const Icon(Icons.add), label: Text(t.rateAdd)),
      body: rates.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(exchangeRatesProvider)),
        data: (list) => ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
          children: [
            Text(t.ratesNeverReprice, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 12),
            for (final r in list)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text('${r['from']} → ${r['to']}  ·  ${r['rate']}'),
                subtitle: Text(r['source'] == 'peg' ? t.rateFixedPeg : t.rateManual),
                trailing: r['source'] == 'peg'
                    ? null
                    : IconButton(tooltip: t.delete, icon: const Icon(Icons.delete_outline), onPressed: () => _delete(r)),
              ),
            const Divider(height: 32),
            Text(t.converterTitle, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            TextField(controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: t.amountLabel)),
            Row(
              children: [
                Expanded(child: DropdownButtonFormField<String>(value: _from, decoration: InputDecoration(labelText: t.rateFrom), items: items, onChanged: (v) => setState(() => _from = v))),
                const SizedBox(width: 12),
                Expanded(child: DropdownButtonFormField<String>(value: _to, decoration: InputDecoration(labelText: t.rateTo), items: items, onChanged: (v) => setState(() => _to = v))),
              ],
            ),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _convert, child: Text(t.convert)),
            if (_result != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_result!, style: Theme.of(context).textTheme.titleMedium)),
          ],
        ),
      ),
    );
  }
}

class _RateForm extends ConsumerStatefulWidget {
  const _RateForm();

  @override
  ConsumerState<_RateForm> createState() => _RateFormState();
}

class _RateFormState extends ConsumerState<_RateForm> {
  final _rate = TextEditingController();
  String? _from;
  String? _to;
  bool _busy = false;

  @override
  void dispose() {
    _rate.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final t = AppLocalizations.of(context)!;
    final rate = _rate.text.trim().replaceAll(',', '.');
    if (_from == null || _to == null || !RegExp(r'^\d+(\.\d{1,8})?$').hasMatch(rate)) {
      showMessage(context, t.errValidation);
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(apiClientProvider).put('/exchange-rates', body: {'from': _from, 'to': _to, 'rate': rate});
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
    final currencies = ref.watch(currenciesProvider).valueOrNull ?? const <Json>[];
    final items = [for (final c in currencies) DropdownMenuItem(value: c['code'] as String, child: Text(c['code'] as String))];
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(t.rateAdd, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(value: _from, decoration: InputDecoration(labelText: t.rateFrom), items: items, onChanged: (v) => setState(() => _from = v)),
          DropdownButtonFormField<String>(value: _to, decoration: InputDecoration(labelText: t.rateTo), items: items, onChanged: (v) => setState(() => _to = v)),
          TextField(controller: _rate, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: t.rateValue, helperText: t.rateHelp)),
          const SizedBox(height: 16),
          FilledButton(onPressed: _busy ? null : _submit, child: Text(t.save)),
        ],
      ),
    );
  }
}
