import 'package:flutter/material.dart';
import 'localization/delegates.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/providers.dart';
import 'design_system/app_theme.dart';
import 'localization/app_localizations.dart';
import 'routing/app_router.dart';

void main() {
  runApp(const ProviderScope(child: MyWifizoneApp()));
}

class MyWifizoneApp extends ConsumerWidget {
  const MyWifizoneApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);

    return MaterialApp.router(
      title: 'MY WIFIZONE',
      theme: buildAppTheme(),
      locale: locale,
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: appLocalizationsDelegates,
      routerConfig: appRouter,
    );
  }
}
