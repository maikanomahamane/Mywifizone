import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import 'network_providers.dart';

/// Formulaire d'ajout d'un routeur. Si aucun site n'existe encore, propose d'en créer un (nom du site).
/// Deux modes : « Identifiants MikroTik » (adresse + admin + mot de passe : le serveur crée un compte dédié limité et oublie
/// l'admin) ou « agent » (code d'enrôlement, pour les routeurs sans IP publique).
/// Se ferme avec la réponse de l'API.
class RouterForm extends ConsumerStatefulWidget {
  const RouterForm({super.key});

  @override
  ConsumerState<RouterForm> createState() => _RouterFormState();
}

class _RouterFormState extends ConsumerState<RouterForm> {
  final _name = TextEditingController();
  final _siteName = TextEditingController();
  final _host = TextEditingController();
  final _port = TextEditingController(text: '443');
  final _user = TextEditingController(text: 'admin');
  final _password = TextEditingController();
  String? _siteId;
  bool _busy = false;
  bool _direct = true;
  bool _verifyTls = false;
  bool _showPassword = false;

  @override
  void dispose() {
    _name.dispose();
    _siteName.dispose();
    _host.dispose();
    _port.dispose();
    _user.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit(bool needsSite) async {
    final t = AppLocalizations.of(context)!;
    final port = int.tryParse(_port.text.trim());
    final directInvalid = _direct && (_host.text.trim().isEmpty || _user.text.trim().isEmpty || _password.text.isEmpty || port == null || port < 1 || port > 65535);
    if (_name.text.trim().isEmpty || (needsSite ? _siteName.text.trim().isEmpty : _siteId == null) || directInvalid) {
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
      final res = await api.post('/routers', body: {
        'site_id': siteId,
        'name': _name.text.trim(),
        if (_direct) ...{
          'connection_mode': 'REST',
          'host': _host.text.trim(),
          'port': port,
          'username': _user.text.trim(),
          'password': _password.text, // jamais trimé ni conservé : envoyé une fois au serveur, qui ne garde que son compte dédié
          'verify_tls': _verifyTls,
        },
      });
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
            const SizedBox(height: 12),
            SegmentedButton<bool>(
              segments: [
                ButtonSegment(value: true, label: Text(t.routerModeDirect)),
                ButtonSegment(value: false, label: Text(t.routerModeAgent)),
              ],
              selected: {_direct},
              onSelectionChanged: _busy ? null : (v) => setState(() => _direct = v.first),
            ),
            if (_direct) ...[
              const SizedBox(height: 12),
              Text(t.routerDirectHelp, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 12),
              TextField(
                controller: _host,
                keyboardType: TextInputType.url,
                autocorrect: false,
                enableSuggestions: false,
                decoration: InputDecoration(labelText: t.routerHost),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _port,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: t.routerPort),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _user,
                autocorrect: false,
                enableSuggestions: false,
                decoration: InputDecoration(labelText: t.routerAdminUser),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _password,
                obscureText: !_showPassword,
                autocorrect: false,
                enableSuggestions: false,
                decoration: InputDecoration(
                  labelText: t.routerAdminPassword,
                  suffixIcon: IconButton(
                    icon: Icon(_showPassword ? Icons.visibility_off : Icons.visibility),
                    onPressed: () => setState(() => _showPassword = !_showPassword),
                  ),
                ),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(t.routerVerifyTls),
                subtitle: Text(t.routerVerifyTlsHelp),
                value: _verifyTls,
                onChanged: (v) => setState(() => _verifyTls = v),
              ),
            ],
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
