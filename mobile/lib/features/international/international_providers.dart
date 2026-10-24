import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../common/format.dart';
import '../network/network_providers.dart';

/// Catalogue des devises actives. Au chargement, alimente [CurrencyFormats] pour que tous les montants
/// s'affichent avec les bonnes unités mineures.
final currenciesProvider = FutureProvider<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/currencies');
  final list = res.list;
  CurrencyFormats.update(list);
  return list;
});

final exchangeRatesProvider = FutureProvider.autoDispose<List<Json>>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/exchange-rates');
  return res.list;
});

/// Devise par défaut du tenant (repli XOF).
String tenantCurrency(Map<String, dynamic>? user) => (user?['tenant']?['currency'] as String?) ?? 'XOF';
