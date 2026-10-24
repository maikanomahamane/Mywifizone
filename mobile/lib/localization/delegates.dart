import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'app_localizations.dart';

/// Flutter ne fournit pas les textes système (Material, Cupertino) pour le hausa (`ha`) : sans repli, les champs de saisie
/// et boutons standard échouent dès que l'utilisateur choisit Hausa. Pour `ha`, ces composants système reprennent l'anglais
/// (sens de lecture gauche-droite, correct pour le hausa) ; tous les textes de l'application, eux, sont en hausa.
class _FallbackDelegate<T> extends LocalizationsDelegate<T> {
  const _FallbackDelegate(this._base, this._unsupported);

  final LocalizationsDelegate<T> _base;
  final Set<String> _unsupported;

  Locale _map(Locale l) => _unsupported.contains(l.languageCode) ? const Locale('en') : l;

  @override
  bool isSupported(Locale locale) => _base.isSupported(_map(locale));

  @override
  Future<T> load(Locale locale) => _base.load(_map(locale));

  @override
  bool shouldReload(covariant _FallbackDelegate<T> old) => false;

  @override
  Type get type => _base.type;
}

const _noSystemTexts = {'ha'};

/// À passer à `localizationsDelegates` de l'application (et des tests).
final List<LocalizationsDelegate<dynamic>> appLocalizationsDelegates = [
  AppLocalizations.delegate,
  const _FallbackDelegate<MaterialLocalizations>(GlobalMaterialLocalizations.delegate, _noSystemTexts),
  const _FallbackDelegate<WidgetsLocalizations>(GlobalWidgetsLocalizations.delegate, _noSystemTexts),
  const _FallbackDelegate<CupertinoLocalizations>(GlobalCupertinoLocalizations.delegate, _noSystemTexts),
];
