import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';

final brandingProvider = FutureProvider.autoDispose<Json>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/branding');
  return res.map;
});

/// Personnalisation des tickets et reçus. Le logo se téléverse via l'API (pas de sélecteur d'image dans cette version).
class BrandingPage extends ConsumerStatefulWidget {
  const BrandingPage({super.key});

  @override
  ConsumerState<BrandingPage> createState() => _BrandingPageState();
}

class _BrandingPageState extends ConsumerState<BrandingPage> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _slogan = TextEditingController();
  final _terms = TextEditingController();
  final _color = TextEditingController();
  final _portal = TextEditingController();
  bool _loaded = false;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [_name, _phone, _slogan, _terms, _color, _portal]) {
      c.dispose();
    }
    super.dispose();
  }

  void _fill(Json b) {
    if (_loaded) return;
    _loaded = true;
    _name.text = (b['display_name'] ?? '') as String;
    _phone.text = (b['phone'] ?? '') as String;
    _slogan.text = (b['slogan'] ?? '') as String;
    _terms.text = (b['terms'] ?? '') as String;
    _color.text = (b['primary_color'] ?? '') as String;
    _portal.text = (b['portal_url'] ?? '') as String;
  }

  String? _v(TextEditingController c) {
    final s = c.text.trim();
    return s.isEmpty ? null : s;
  }

  Future<void> _save() async {
    final t = AppLocalizations.of(context)!;
    setState(() => _busy = true);
    try {
      await ref.read(apiClientProvider).put('/branding', body: {
        'display_name': _v(_name),
        'phone': _v(_phone),
        'slogan': _v(_slogan),
        'terms': _v(_terms),
        'primary_color': _v(_color),
        'portal_url': _v(_portal),
      });
      ref.invalidate(brandingProvider);
      if (mounted) showMessage(context, t.brandingSaved);
    } catch (e) {
      if (mounted) showMessage(context, errorText(t, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    final branding = ref.watch(brandingProvider);
    return Scaffold(
      appBar: AppBar(title: Text(t.brandingTitle)),
      body: branding.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(brandingProvider)),
        data: (b) {
          _fill(b);
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              TextField(controller: _name, maxLength: 80, decoration: InputDecoration(labelText: t.brandingName)),
              TextField(controller: _phone, maxLength: 30, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: t.brandingPhone)),
              TextField(controller: _slogan, maxLength: 120, decoration: InputDecoration(labelText: t.brandingSlogan)),
              TextField(controller: _terms, maxLength: 600, maxLines: 3, decoration: InputDecoration(labelText: t.brandingTerms)),
              TextField(controller: _color, maxLength: 7, decoration: InputDecoration(labelText: t.brandingColor, hintText: '#EF3340')),
              TextField(
                controller: _portal,
                maxLength: 200,
                keyboardType: TextInputType.url,
                decoration: InputDecoration(labelText: t.brandingPortal, helperText: t.brandingPortalHelp, helperMaxLines: 3),
              ),
              const SizedBox(height: 8),
              Text(t.brandingLogoWeb, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 16),
              FilledButton(onPressed: _busy ? null : _save, child: Text(t.save)),
            ],
          );
        },
      ),
    );
  }
}
