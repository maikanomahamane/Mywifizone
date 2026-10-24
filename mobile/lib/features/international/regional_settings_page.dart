import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import 'international_providers.dart';

const _timezones = [
  'Africa/Niamey', 'Africa/Abidjan', 'Africa/Dakar', 'Africa/Lagos', 'Africa/Accra', 'Africa/Douala',
  'Africa/Nairobi', 'Africa/Casablanca', 'Europe/Paris', 'Europe/London', 'America/New_York', 'UTC',
];

/// Langue, devise par défaut, pays et fuseau du tenant. Changer la devise ne convertit AUCUN prix existant.
class RegionalSettingsPage extends ConsumerStatefulWidget {
  const RegionalSettingsPage({super.key});

  @override
  ConsumerState<RegionalSettingsPage> createState() => _RegionalSettingsPageState();
}

class _RegionalSettingsPageState extends ConsumerState<RegionalSettingsPage> {
  final _country = TextEditingController();
  String? _locale;
  String? _currency;
  String? _timezone;
  bool _loaded = false;
  bool _busy = false;

  @override
  void dispose() {
    _country.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    if (_loaded) return;
    _loaded = true;
    try {
      final tenant = (await ref.read(apiClientProvider).get('/tenants/me')).map;
      if (!mounted) return;
      setState(() {
        _locale = tenant['locale'] as String?;
        _currency = tenant['currency'] as String?;
        _timezone = tenant['timezone'] as String?;
        _country.text = (tenant['country'] ?? '') as String;
      });
    } catch (_) {
      // la page reste utilisable : les champs vides ne sont pas envoyés
    }
  }

  Future<void> _save() async {
    final t = AppLocalizations.of(context)!;
    final country = _country.text.trim().toUpperCase();
    if (country.isNotEmpty && !RegExp(r'^[A-Z]{2}$').hasMatch(country)) {
      showMessage(context, t.errValidation);
      return;
    }
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiClientProvider).put('/tenants/me', body: {
        if (_locale != null) 'locale': _locale,
        if (_currency != null) 'currency': _currency,
        if (_timezone != null) 'timezone': _timezone,
        if (country.isNotEmpty) 'country': country,
      });
      final tenant = res.map;
      final user = ref.read(currentUserProvider);
      if (user != null) {
        ref.read(currentUserProvider.notifier).state = {
          ...user,
          'tenant': {...Map<String, dynamic>.from(user['tenant'] as Map? ?? {}), 'currency': tenant['currency']},
        };
      }
      if (_locale != null) ref.read(localeProvider.notifier).state = Locale(_locale!);
      ref.invalidate(profilesProvider);
      if (mounted) showMessage(context, t.regionalSaved);
    } catch (e) {
      if (mounted) showMessage(context, errorText(t, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    _load();
    final currencies = ref.watch(currenciesProvider).valueOrNull ?? const <Json>[];
    final tzItems = {..._timezones, if (_timezone != null) _timezone!}.toList();

    return Scaffold(
      appBar: AppBar(title: Text(t.regionalTitle)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          DropdownButtonFormField<String>(
            value: _locale,
            decoration: InputDecoration(labelText: t.languageLabel),
            items: const [
              DropdownMenuItem(value: 'fr', child: Text('Français')),
              DropdownMenuItem(value: 'en', child: Text('English')),
              DropdownMenuItem(value: 'ar', child: Text('العربية')),
              DropdownMenuItem(value: 'ha', child: Text('Hausa')),
            ],
            onChanged: (v) => setState(() => _locale = v),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: currencies.any((c) => c['code'] == _currency) ? _currency : null,
            isExpanded: true,
            decoration: InputDecoration(labelText: t.defaultCurrency, helperText: t.currencyNoRepricing, helperMaxLines: 3),
            items: [
              for (final c in currencies) DropdownMenuItem(value: c['code'] as String, child: Text('${c['code']} · ${c['name']}')),
            ],
            onChanged: (v) => setState(() => _currency = v),
          ),
          const SizedBox(height: 12),
          TextField(controller: _country, maxLength: 2, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: t.countryCode)),
          DropdownButtonFormField<String>(
            value: _timezone,
            isExpanded: true,
            decoration: InputDecoration(labelText: t.timezoneLabel),
            items: [for (final z in tzItems) DropdownMenuItem(value: z, child: Text(z))],
            onChanged: (v) => setState(() => _timezone = v),
          ),
          const SizedBox(height: 16),
          FilledButton(onPressed: _busy ? null : _save, child: Text(t.save)),
        ],
      ),
    );
  }
}
