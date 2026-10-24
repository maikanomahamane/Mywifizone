import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/format.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import '../documents/ticket_export_sheet.dart';
import '../sales/sales_providers.dart';
import 'generate_batch_sheet.dart';
import 'profiles_page.dart';

const _filters = <String?>[null, 'AVAILABLE', 'SOLD', 'ACTIVE', 'USED', 'EXPIRED', 'REVOKED', 'VIP', 'GENERATED'];

class TicketsPage extends ConsumerStatefulWidget {
  const TicketsPage({super.key});

  @override
  ConsumerState<TicketsPage> createState() => _TicketsPageState();
}

class _TicketsPageState extends ConsumerState<TicketsPage> {
  String? _status;

  Future<void> _revoke(Json voucher) async {
    final t = AppLocalizations.of(context)!;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${t.revoke} · ${voucher['code']}'),
        content: Text(t.revokeConfirm),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(t.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(t.revoke)),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await ref.read(apiClientProvider).post('/vouchers/${voucher['id']}/revoke');
      ref.invalidate(vouchersProvider);
    } catch (e) {
      if (mounted) showMessage(context, errorText(t, e));
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    final vouchers = ref.watch(vouchersProvider(_status));

    return Scaffold(
      appBar: AppBar(
        title: Text(t.ticketsTitle),
        actions: [
          if (can(ref, 'tickets.export'))
            IconButton(
              tooltip: t.exportTickets,
              icon: const Icon(Icons.print_outlined),
              onPressed: () => showModalBottomSheet<void>(
                context: context,
                isScrollControlled: true,
                builder: (_) => const TicketExportSheet(),
              ),
            ),
          IconButton(
            tooltip: t.profilesTitle,
            icon: const Icon(Icons.tune),
            onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const ProfilesPage())),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final created = await showModalBottomSheet<bool>(
            context: context,
            isScrollControlled: true,
            builder: (_) => const GenerateBatchSheet(),
          );
          if (created == true) ref.invalidate(vouchersProvider);
        },
        icon: const Icon(Icons.add),
        label: Text(t.ticketsGenerate),
      ),
      body: Column(
        children: [
          SizedBox(
            height: 52,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              itemCount: _filters.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (_, i) {
                final f = _filters[i];
                return ChoiceChip(
                  label: Text(f == null ? t.all : voucherStatusLabel(t, f)),
                  selected: _status == f,
                  onSelected: (_) => setState(() => _status = f),
                );
              },
            ),
          ),
          Expanded(
            child: vouchers.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(vouchersProvider)),
              data: (list) => RefreshIndicator(
                onRefresh: () async => ref.invalidate(vouchersProvider),
                child: list.isEmpty
                    ? ListView(children: [const SizedBox(height: 80), EmptyView(t.ticketsEmpty)])
                    : ListView.separated(
                        padding: const EdgeInsets.only(bottom: 88),
                        itemCount: list.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (_, i) => _VoucherTile(voucher: list[i], onRevoke: _revoke),
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _VoucherTile extends StatelessWidget {
  const _VoucherTile({required this.voucher, required this.onRevoke});
  final Json voucher;
  final Future<void> Function(Json) onRevoke;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    final status = voucher['status'] as String;
    final revocable = !{'USED', 'EXPIRED', 'REVOKED'}.contains(status);
    final subtitle = [
      formatMoney(voucher['price'] as num, voucher['currency'] as String),
      if (voucher['rate_limit'] != null) voucher['rate_limit'],
      if (voucher['sync_status'] == 'FAILED') t.syncFailed,
    ].join(' · ');

    return ListTile(
      title: Text(voucher['code'] as String, style: const TextStyle(fontFamily: 'monospace', fontWeight: FontWeight.w700, letterSpacing: 1.5)),
      subtitle: Text(subtitle),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          StatusChip(label: voucherStatusLabel(t, status), color: voucherStatusColor(status)),
          if (revocable)
            IconButton(tooltip: t.revoke, icon: const Icon(Icons.block), onPressed: () => onRevoke(voucher)),
        ],
      ),
    );
  }
}
