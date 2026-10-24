import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import '../documents/document_actions.dart';
import 'cash_page.dart';
import 'sales_providers.dart';
import 'sell_sheet.dart';

class SalesPage extends ConsumerWidget {
  const SalesPage({super.key});

  Future<void> _sell(BuildContext context, WidgetRef ref) async {
    final sale = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const SellSheet(),
    );
    if (sale == null || !context.mounted) return;
    ref.invalidate(salesProvider);
    ref.invalidate(dashboardProvider);
    ref.invalidate(cashSessionProvider);
    ref.invalidate(vouchersProvider);
    await showDialog<void>(context: context, builder: (_) => _CodeDialog(sale: sale));
  }

  Future<void> _void(BuildContext context, WidgetRef ref, Json sale) async {
    final t = AppLocalizations.of(context)!;
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(t.voidSale),
        content: TextField(controller: reason, decoration: InputDecoration(labelText: t.voidReason)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(t.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t.confirm)),
        ],
      ),
    );
    final text = reason.text.trim();
    reason.dispose();
    if (ok != true || !context.mounted) return;
    if (text.length < 3) {
      showMessage(context, t.errValidation);
      return;
    }
    try {
      await ref.read(apiClientProvider).post('/sales/${sale['id']}/void', body: {'reason': text});
      ref.invalidate(salesProvider);
      ref.invalidate(dashboardProvider);
      ref.invalidate(cashSessionProvider);
    } catch (e) {
      if (context.mounted) showMessage(context, errorText(t, e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final sales = ref.watch(salesProvider);
    final canVoid = can(ref, 'sales.void');

    return Scaffold(
      appBar: AppBar(
        title: Text(t.salesTitle),
        actions: [
          IconButton(
            tooltip: t.cashTitle,
            icon: const Icon(Icons.account_balance_wallet_outlined),
            onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const CashPage())),
          ),
        ],
      ),
      floatingActionButton: can(ref, 'sales.create')
          ? FloatingActionButton.extended(
              onPressed: () => _sell(context, ref),
              icon: const Icon(Icons.point_of_sale),
              label: Text(t.sellButton),
            )
          : null,
      body: sales.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(salesProvider)),
        data: (list) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(salesProvider),
          child: list.isEmpty
              ? ListView(children: [const SizedBox(height: 80), EmptyView(t.salesEmpty)])
              : ListView.separated(
                  padding: const EdgeInsets.only(bottom: 88),
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (_, i) {
                    final s = list[i];
                    final voided = s['status'] == 'VOIDED';
                    return ListTile(
                      title: Text(
                        formatMoney(s['amount'] as num, s['currency'] as String),
                        style: TextStyle(fontWeight: FontWeight.w700, decoration: voided ? TextDecoration.lineThrough : null),
                      ),
                      subtitle: Text('${shortDateTime(s['sold_at'] as String?)} · ${paymentLabel(t, s['payment_method'] as String)}'),
                      trailing: voided
                          ? StatusChip(label: t.saleVoided, color: Colors.grey)
                          : (canVoid
                              ? IconButton(tooltip: t.voidSale, icon: const Icon(Icons.undo), onPressed: () => _void(context, ref, s))
                              : null),
                    );
                  },
                ),
        ),
      ),
    );
  }
}

/// Affiche le code vendu en grand : c'est le seul moment où l'API le renvoie.
class _CodeDialog extends ConsumerWidget {
  const _CodeDialog({required this.sale});
  final Map<String, dynamic> sale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final code = (sale['voucher_code'] as String?) ?? '';
    return AlertDialog(
      title: Text(t.sellCodeTitle),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SelectableText(
            code,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 30, fontWeight: FontWeight.w800, letterSpacing: 3),
          ),
          const SizedBox(height: 8),
          Text(formatMoney(sale['amount'] as num, sale['currency'] as String)),
          const SizedBox(height: 12),
          Text(t.sellCodeHint, textAlign: TextAlign.center),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () async {
            await Clipboard.setData(ClipboardData(text: code));
            if (context.mounted) showMessage(context, t.copied);
          },
          child: Text(t.copy),
        ),
        TextButton(
          onPressed: () async {
            try {
              final locale = ref.read(localeProvider).languageCode;
              final file = await ref.read(apiClientProvider).download('GET', '/sales/${sale['id']}/receipt',
                  query: {'format': 'thermal', 'locale': locale});
              await shareFile(file.bytes, 'recu-${sale['id']}.pdf', 'application/pdf');
            } catch (e) {
              if (context.mounted) showMessage(context, errorText(t, e));
            }
          },
          child: Text(t.receiptPdf),
        ),
        FilledButton(onPressed: () => Navigator.pop(context), child: Text(t.close)),
      ],
    );
  }
}
