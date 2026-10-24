import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api_client.dart';
import 'token_storage.dart';

final tokenStorageProvider = Provider<TokenStorage>((ref) => TokenStorage());

final apiClientProvider =
    Provider<ApiClient>((ref) => ApiClient(ref.watch(tokenStorageProvider)));

/// Langue choisie : fr, en, ar (RTL automatique via Directionality) ou ha.
final localeProvider = StateProvider<Locale>((ref) => const Locale('fr'));

/// Profil de l'utilisateur connecté (null = déconnecté).
final currentUserProvider = StateProvider<Map<String, dynamic>?>((ref) => null);
