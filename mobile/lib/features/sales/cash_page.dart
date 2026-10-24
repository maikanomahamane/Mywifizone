import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import 'sales_providers.dart';

String _entryLabel(AppLocalizations t, String type) => switch (type) {
      'OPEN' => t.cashOpen,
      'CASH_IN' => t.cashEntryCashIn,
      'SALE' => t.salesTitle,
      'EXPENSE' => t.cashEntryExpense,
      'WITHDRAWAL' => t.cashEntryWithdrawal,
      'CLOSE' => t.cashClose,
      _ => type,
    };

/// Caisse de l'utilisateur : ouverture, opérations manuelles, clôture avec écart. Les montants sont saisis en unités
/// mineures de la devise de la caisse (XOF : en francs).
class CashPage extends ConsumerWidget {
  const CashPage({super.key});

  void _refresh(WidgetRef ref) {
    ref.invalidate(cashSessionProvider);
    ref.invalidate(cashRegistersProvider);
    ref.invalidate(dashboardProvider);
  }

  Future<int?> _askAmount(BuildContext context, String title, {String? label, bool withNote = false, void Function(String)? onNote}) async {
    final t = AppLocalizations.of(context)!;
    final amount = TextEditingController();
    final note = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: amount,
              autofocus: true,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: label ?? t.amountLabel),
            ),
            if (withNote) TextField(controller: note, decoration: InputDecoration(labelText: t.noteLabel)),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(t.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t.confirm)),
        ],
      ),
    );
    final value = int.tryParse(amount.text.trim());
    onNote?.call(note.text.trim());
    amount.dispose();
    note.dispose();
    return ok == true ? (value ?? -1) : null;
  }

  Future<void> _call(BuildContext context, WidgetRef ref, Future<void> Function() action) async {
    final t = AppLocalizations.of(context)!;
    try {
      await action();
      _refresh(ref);
    } catch (e) {
      if (context.mounted) showMessage(context, errorText(t, e));
    }
  }

  Future<void> _open(BuildContext context, WidgetRef ref, Json register) async {
    final t = AppLocalizations.of(context)!;
    final amount = await _askAmount(context, t.cashOpen, label: t.cashOpeningBalance);
    if (amount == null || !context.mounted) return;
    if (amount < 0) return showMessage(context, t.errValidation);
    await _call(context, ref, () async {
      await ref.read(apiClientProvider).post('/cash-sessions', body: {'cash_register_id': register['id'], 'opening_balance': amount});
    });
  }

  Future<void> _entry(BuildContext context, WidgetRef ref, Json session, String type) async {
    final t = AppLocalizations.of(context)!;
    var note = '';
    final amount = await _askAmount(context, _entryLabel(t, type), withNote: true, onNote: (n) => note = n);
    if (amount == null || !context.mounted) return;
    if (amount <= 0) return showMessage(context, t.errValidation);
    await _call(context, ref, () async {
      await ref.read(apiClientProvider).post('/cash-sessions/${session['id']}/transactions', body: {
        'type': type,
        'amount': amount,
        if (note.isNotEmpty) 'note': note,
      });
      ref.invalidate(cashTransactionsProvider(session['id'] as String));
    });
  }

  Future<void> _close(BuildContext context, WidgetRef ref, Json session) async {
    final t = AppLocalizations.of(context)!;
    final counted = await _askAmount(context, t.cashClose, label: t.cashCounted);
    if (counted == null || !context.mounted) return;
    if (counted < 0) return showMessage(context, t.errValidation);
    try {
      final res = await ref.read(apiClientProvider).post('/cash-sessions/${session['id']}/close', body: {'counted_balance': counted});
      _refresh(ref);
      if (!context.mounted) return;
      final diff = (res.map['difference'] as num?) ?? 0;
      showMessage(context, '${t.cashDifference} : ${formatMoney(diff, session['currency'] as String)}');
    } catch (e) {
      if (context.mounted) showMessage(context, errorText(t, e));
    }
  }

  Future<void> _createRegister(BuildContext context, WidgetRef ref) async {
    final t = AppLocalizations.of(context)!;
    final name = TextEditingController();
    final currency = TextEditingController(text: 'XOF');
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(t.cashCreateRegister),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: name, decoration: InputDecoration(labelText: t.cashRegisterName)),
          TextField(controller: currency, decoration: InputDecoration(labelText: t.currencyLabel), textCapitalization: TextCapitalization.characters),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(t.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t.create)),
        ],
      ),
    );
    final n = name.text.trim();
    final c = currency.text.trim().toUpperCase();
    name.dispose();
    currency.dispose();
    if (ok != true || !context.mounted) return;
    await _call(context, ref, () async {
      await ref.read(apiClientProvider).post('/cash-registers', body: {'name': n, 'currency': c});
    });
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final session = ref.watch(cashSessionProvider);
    final registers = ref.watch(cashRegistersProvider);

    return Scaffold(
      appBar: AppBar(title: Text(t.cashTitle)),
      body: session.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => _refresh(ref)),
        data: (s) => RefreshIndicator(
          onRefresh: () async => _refresh(ref),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(16),
            children: s == null ? _closedBody(context, ref, t, registers) : _openBody(context, ref, t, s),
          ),
        ),
      ),
    );
  }

  List<Widget> _closedBody(BuildContext context, WidgetRef ref, AppLocalizations t, AsyncValue<List<Json>> registers) {
    final list = (registers.valueOrNull ?? const <Json>[]).where((r) => r['is_active'] == true).toList();
    return [
      Text(t.cashNoSession, style: Theme.of(context).textTheme.titleMedium),
      const SizedBox(height: 12),
      if (registers.hasError) ErrorView(error: registers.error!, onRetry: () => ref.invalidate(cashRegistersProvider)),
      for (final r in list)
        Card(
          child: ListTile(
            title: Text(r['name'] as String),
            subtitle: Text(r['currency'] as String),
            trailing: FilledButton(onPressed: () => _open(context, ref, r), child: Text(t.cashOpen)),
          ),
        ),
      if (registers.hasValue && list.isEmpty && can(ref, 'cash.manage'))
        OutlinedButton.icon(onPressed: () => _createRegister(context, ref), icon: const Icon(Icons.add), label: Text(t.cashCreateRegister)),
      if (registers.hasValue && list.isEmpty && !can(ref, 'cash.manage')) EmptyView(t.cashNoSession),
    ];
  }

  List<Widget> _openBody(BuildContext context, WidgetRef ref, AppLocalizations t, Json s) {
    final currency = s['currency'] as String;
    final tx = ref.watch(cashTransactionsProvider(s['id'] as String));
    return [
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(t.cashBalance, style: Theme.of(context).textTheme.bodySmall),
            Text(formatMoney((s['current_balance'] as num?) ?? 0, currency), style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 4),
            Text('${t.cashOpeningBalance} : ${formatMoney(s['opening_balance'] as num, currency)}', style: Theme.of(context).textTheme.bodySmall),
          ]),
        ),
      ),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final type in const ['CASH_IN', 'EXPENSE', 'WITHDRAWAL'])
          OutlinedButton(onPressed: () => _entry(context, ref, s, type), child: Text(_entryLabel(t, type))),
        FilledButton.tonal(onPressed: () => _close(context, ref, s), child: Text(t.cashClose)),
      ]),
      const SizedBox(height: 16),
      Text(t.cashJournal, style: Theme.of(context).textTheme.titleMedium),
      tx.when(
        loading: () => const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(cashTransactionsProvider(s['id'] as String))),
        data: (list) => Column(children: [
          for (final e in list)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(_entryLabel(t, e['type'] as String)),
              subtitle: Text([shortDateTime(e['created_at'] as String?), if (e['note'] != null) e['note']].join(' · ')),
              trailing: Text(formatMoney(e['amount'] as num, e['currency'] as String)),
            ),
        ]),
      ),
    ];
  }
}
