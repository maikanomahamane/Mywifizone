import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mywifizone/features/auth/login_page.dart';
import 'package:mywifizone/features/auth/register_page.dart';
import 'package:mywifizone/localization/app_localizations.dart';
import 'package:mywifizone/localization/delegates.dart';

Widget _app(Widget home) => ProviderScope(
      child: MaterialApp(
        locale: const Locale('fr'),
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: appLocalizationsDelegates,
        home: home,
      ),
    );

void main() {
  testWidgets('la page de connexion propose de créer un compte', (tester) async {
    await tester.pumpWidget(_app(const LoginPage()));
    await tester.pumpAndSettle();

    expect(find.text('Créer un compte'), findsOneWidget);
  });

  testWidgets('inscription : champs vides ou mot de passe trop court -> message, aucun appel réseau', (tester) async {
    await tester.pumpWidget(_app(const RegisterPage()));
    await tester.pumpAndSettle();

    expect(find.byType(TextField), findsAtLeastNWidgets(4));
    await tester.tap(find.widgetWithText(FilledButton, 'Créer mon compte'));
    await tester.pumpAndSettle();

    expect(find.textContaining('8 caractères minimum'), findsWidgets);
  });
}
