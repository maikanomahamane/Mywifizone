import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import 'network_providers.dart';

/// Formulaire d'ajout d'un routeur. Si aucun site n'existe encore, propose d'en créer un (nom du site).
/// Se ferme avec la réponse de l'API (qui contient le code d'enrôlement).
class RouterForm extends ConsumerStatefulWidget {
  const RouterForm({super.key});

  @override
  ConsumerState<RouterForm> createState() => _RouterFormState();
}

class _RouterFormState extends ConsumerState<RouterForm> {
  final _name = TextEditingController();
  final _siteName = TextEditingController();
  String? _siteId;
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _siteName.dispose();
    super.dispose();
  }

  Future<void> _submit(bool needsSite) async {
    final t = AppLocalizations.of(context)!;
    if (_name.text.trim().isEmpty || (needsSite ? _siteName.text.trim().isEmpty : _siteId == null)) {
      showMessage(context, t.errValidation);
      return;
    }
    setState(() => _busy = true);
    try {
      final api = ref.read(apiClientProvider);
      var siteId = _siteId;
      if (needsSite) {
        final site = await api.post('/sites', body: {'name': _siteName.text.trim()});
        siteId = site.map['id'] as String;
        ref.invalidate(sitesProvider);
      }
      final res = await api.post('/routers', body: {'site_id': siteId, 'name': _name.text.trim()});
      if (mounted) Navigator.pop(context, res.map);
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        showMessage(context, errorText(t, e));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    final sites = ref.watch(sitesProvider);
    final list = sites.valueOrNull ?? const <Json>[];
    final needsSite = sites.hasValue && list.isEmpty;

    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(t.routerAdd, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 12),
            if (needsSite)
              TextField(controller: _siteName, decoration: InputDecoration(labelText: t.siteName))
            else
              DropdownButtonFormField<String>(
                value: _siteId,
                isExpanded: true,
                decoration: InputDecoration(labelText: t.routerSite),
                items: [for (final s in list) DropdownMenuItem(value: s['id'] as String, child: Text(s['name'] as String))],
                onChanged: (v) => setState(() => _siteId = v),
              ),
            const SizedBox(height: 12),
            TextField(controller: _name, decoration: InputDecoration(labelText: t.routerName)),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _busy || !sites.hasValue ? null : () => _submit(needsSite),
              child: _busy
                  ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Text(t.create),
            ),
          ],
        ),
      ),
    );
  }
}
