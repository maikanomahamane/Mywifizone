import 'package:flutter/material.dart';
import 'package:mywifizone/localization/delegates.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mywifizone/features/auth/login_page.dart';
import 'package:mywifizone/localization/app_localizations.dart';

Widget _app(Locale locale) => ProviderScope(
      child: MaterialApp(
        locale: locale,
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: appLocalizationsDelegates,
        home: const LoginPage(),
      ),
    );

void main() {
  testWidgets('FR : texte de connexion, direction LTR', (tester) async {
    await tester.pumpWidget(_app(const Locale('fr')));
    await tester.pumpAndSettle();

    expect(find.text('Se connecter'), findsOneWidget);
    expect(Directionality.of(tester.element(find.byType(LoginPage))), TextDirection.ltr);
  });

  testWidgets('AR : direction RTL', (tester) async {
    await tester.pumpWidget(_app(const Locale('ar')));
    await tester.pumpAndSettle();

    expect(find.text('دخول'), findsOneWidget);
    expect(Directionality.of(tester.element(find.byType(LoginPage))), TextDirection.rtl);
  });

  testWidgets('HA : chaînes traduites', (tester) async {
    await tester.pumpWidget(_app(const Locale('ha')));
    await tester.pumpAndSettle();

    expect(find.text('Shiga'), findsWidgets);
  });
}
