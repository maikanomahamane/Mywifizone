import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../network/network_providers.dart';

final dashboardProvider = FutureProvider.autoDispose<Json>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/dashboard');
  return res.map;
});

final salesProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/sales', query: {'per_page': 50});
  return res.list;
});

/// Ma session de caisse ouverte, ou null.
final cashSessionProvider = FutureProvider.autoDispose<Json?>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/cash-sessions/current');
  return res.data == null ? null : res.map;
});

final cashRegistersProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/cash-registers', query: {'per_page': 100});
  return res.list;
});

final cashTransactionsProvider = FutureProvider.autoDispose.family<List<Json>, String>((ref, sessionId) async {
  final res = await ref.watch(apiClientProvider).get('/cash-sessions/$sessionId/transactions', query: {'per_page': 100});
  return res.list;
});

final agentsProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/agents', query: {'per_page': 100});
  return res.list;
});

/// Paramètres d'un rapport (égalité par valeur : indispensable pour `family`).
class ReportQuery {
  const ReportQuery(this.period, this.from, this.to);
  final String period;
  final String from;
  final String to;

  @override
  bool operator ==(Object other) =>
      other is ReportQuery && other.period == period && other.from == from && other.to == to;

  @override
  int get hashCode => Object.hash(period, from, to);
}

final reportProvider = FutureProvider.autoDispose.family<Json, ReportQuery>((ref, q) async {
  final res = await ref.watch(apiClientProvider).get('/reports/sales', query: {
    'period': q.period,
    'from': q.from,
    'to': q.to,
  });
  return res.map;
});

/// Permission de l'utilisateur connecté (la vérification qui fait foi reste côté serveur).
bool can(WidgetRef ref, String permission) {
  final perms = ref.watch(currentUserProvider)?['permissions'];
  return perms is List && perms.contains(permission);
}
