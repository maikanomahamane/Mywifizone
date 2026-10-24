import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../localization/app_localizations.dart';
import '../../core/providers.dart';
import '../common/format.dart';
import '../documents/document_actions.dart';
import '../common/widgets.dart';
import 'sales_providers.dart';

/// Rapport de ventes par jour / semaine / mois. Les semaines et mois dépendent de la formule (erreur `feature_unavailable`).
class ReportsPage extends ConsumerStatefulWidget {
  const ReportsPage({super.key});

  @override
  ConsumerState<ReportsPage> createState() => _ReportsPageState();
}

class _ReportsPageState extends ConsumerState<ReportsPage> {
  String _period = 'daily';

  ReportQuery get _query {
    final now = DateTime.now();
    final from = switch (_period) {
      'weekly' => now.subtract(const Duration(days: 8 * 7)),
      'monthly' => DateTime(now.year, now.month - 11, 1),
      _ => now.subtract(const Duration(days: 13)),
    };
    return ReportQuery(_period, isoDate(from), isoDate(now));
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    final query = _query;
    final report = ref.watch(reportProvider(query));

    return Scaffold(
      appBar: AppBar(
        title: Text(t.reportsTitle),
        actions: [
          IconButton(
            tooltip: t.csvExport,
            icon: const Icon(Icons.file_download_outlined),
            onPressed: () async {
              try {
                final file = await ref.read(apiClientProvider).download('GET', '/exports/sales.csv',
                    query: {'from': query.from, 'to': query.to});
                await shareFile(file.bytes, 'ventes-${query.from}_${query.to}.csv', 'text/csv');
              } catch (e) {
                if (context.mounted) showMessage(context, errorText(t, e));
              }
            },
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'daily', label: Text(t.reportDaily)),
                ButtonSegment(value: 'weekly', label: Text(t.reportWeekly)),
                ButtonSegment(value: 'monthly', label: Text(t.reportMonthly)),
              ],
              selected: {_period},
              onSelectionChanged: (s) => setState(() => _period = s.first),
            ),
          ),
          Expanded(
            child: report.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(reportProvider(query))),
              data: (data) {
                final rows = (data['rows'] as List).cast<Map>();
                final totals = (data['totals'] as List).cast<Map>();
                if (rows.isEmpty) return EmptyView(t.reportEmpty);
                return ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    for (final tot in totals)
                      Card(
                        child: ListTile(
                          title: Text('${t.reportTotal} · ${tot['currency']}'),
                          subtitle: Text('${tot['sales_count']} × ${t.salesTitle}'),
                          trailing: Text(formatMoney(tot['total'] as num, tot['currency'] as String),
                              style: Theme.of(context).textTheme.titleMedium),
                        ),
                      ),
                    const SizedBox(height: 8),
                    for (final r in rows.reversed)
                      ListTile(
                        dense: true,
                        title: Text(r['period_start'] as String),
                        subtitle: Text('${r['sales_count']} × ${t.salesTitle}'),
                        trailing: Text(formatMoney(r['total'] as num, r['currency'] as String)),
                      ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
