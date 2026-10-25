import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mywifizone/features/network/network_providers.dart';
import 'package:mywifizone/features/network/router_form.dart';
import 'package:mywifizone/localization/app_localizations.dart';
import 'package:mywifizone/localization/delegates.dart';

Widget _app() => ProviderScope(
      overrides: [
        sitesProvider.overrideWith((ref) async => [
              {'id': 's1', 'name': 'Boutique'}
            ]),
      ],
      child: MaterialApp(
        locale: const Locale('fr'),
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: appLocalizationsDelegates,
        home: const Scaffold(body: RouterForm()),
      ),
    );

void main() {
  testWidgets("ajout d'un routeur : identifiants MikroTik par défaut, mot de passe masqué", (tester) async {
    await tester.pumpWidget(_app());
    await tester.pumpAndSettle();

    expect(find.text('Identifiants MikroTik'), findsOneWidget);
    expect(find.text("Adresse du routeur (IP ou domaine)"), findsOneWidget);
    expect(find.text('Mot de passe admin du MikroTik'), findsOneWidget);

    final password = tester.widget<TextField>(find.widgetWithText(TextField, 'Mot de passe admin du MikroTik'));
    expect(password.obscureText, isTrue);
    // L'identifiant admin est prérempli, le port aussi.
    expect(find.widgetWithText(TextField, 'admin'), findsOneWidget);
    expect(find.widgetWithText(TextField, '443'), findsOneWidget);
  });

  testWidgets('champs obligatoires vides : message de validation, aucun appel réseau', (tester) async {
    await tester.pumpWidget(_app());
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Créer'));
    await tester.pumpAndSettle();

    expect(find.text('Données invalides. Vérifiez les champs.'), findsOneWidget);
  });

  testWidgets('mode agent : les champs de connexion directe disparaissent', (tester) async {
    await tester.pumpWidget(_app());
    await tester.pumpAndSettle();

    await tester.tap(find.text('Avancé : agent'));
    await tester.pumpAndSettle();

    expect(find.text('Mot de passe admin du MikroTik'), findsNothing);
    expect(find.text('Identifiant admin du MikroTik'), findsNothing);
  });
}
