import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';

typedef Json = Map<String, dynamic>;

/// Toutes les listes sont rechargées avec `ref.invalidate(...)` après une action ou un tirer-pour-rafraîchir.
final routersProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/routers', query: {'per_page': 100});
  return res.list;
});

final sitesProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/sites', query: {'per_page': 100});
  return res.list;
});

final profilesProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/profiles', query: {'per_page': 100});
  return res.list;
});

final sessionsProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/sessions', query: {'per_page': 100});
  return res.list;
});

/// Filtre = statut (null = tous).
final vouchersProvider = FutureProvider.autoDispose.family<List<Json>, String?>((ref, status) async {
  final res = await ref.watch(apiClientProvider).get('/vouchers', query: {
    'per_page': 100,
    if (status != null) 'status': status,
  });
  return res.list;
});

final voucherCountProvider = FutureProvider.autoDispose.family<int, String>((ref, status) async {
  final res = await ref.watch(apiClientProvider).get('/vouchers', query: {'per_page': 1, 'status': status});
  return (res.meta['total'] as num?)?.toInt() ?? 0;
});

final subscriptionProvider = FutureProvider.autoDispose<Json>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/subscription');
  return res.map;
});
