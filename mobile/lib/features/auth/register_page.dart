import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/api_config.dart';
import '../../core/providers.dart';
import '../../design_system/app_colors.dart';
import '../../localization/app_localizations.dart';

/// Inscription d'un propriétaire : crée le compte, la WiFi zone et l'essai gratuit, puis connecte directement.
class RegisterPage extends ConsumerStatefulWidget {
  const RegisterPage({super.key});

  @override
  ConsumerState<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends ConsumerState<RegisterPage> {
  final _name = TextEditingController();
  final _business = TextEditingController();
  final _identifier = TextEditingController();
  final _password = TextEditingController();
  final _server = TextEditingController(text: apiBaseUrl);
  bool _loading = false;
  String? _errorCode;

  @override
  void initState() {
    super.initState();
    _loadServer();
  }

  Future<void> _loadServer() async {
    final saved = await ref.read(tokenStorageProvider).serverUrl;
    if (saved != null && saved.isNotEmpty && mounted) {
      _server.text = saved;
      ref.read(apiClientProvider).setBaseUrl(saved);
    }
  }

  @override
  void dispose() {
    for (final c in [_name, _business, _identifier, _password, _server]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final identifier = _identifier.text.trim();
    if (_name.text.trim().isEmpty || _business.text.trim().isEmpty || identifier.isEmpty || _password.text.length < 8) {
      setState(() => _errorCode = 'validation_failed');
      return;
    }
    setState(() {
      _loading = true;
      _errorCode = null;
    });
    try {
      final api = ref.read(apiClientProvider);
      final server = _server.text.trim();
      if (server.isNotEmpty) {
        api.setBaseUrl(server);
        await ref.read(tokenStorageProvider).saveServerUrl(server);
      }
      final user = await api.register(
        name: _name.text.trim(),
        businessName: _business.text.trim(),
        identifier: identifier,
        password: _password.text,
      );
      ref.read(currentUserProvider.notifier).state = user;
      final userLocale = user['locale'];
      if (userLocale is String && const ['fr', 'en', 'ar', 'ha'].contains(userLocale)) {
        ref.read(localeProvider.notifier).state = Locale(userLocale);
      }
      if (mounted) context.go('/home');
    } on ApiException catch (e) {
      setState(() => _errorCode = e.code);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _message(AppLocalizations t, String code) => switch (code) {
        'validation_failed' => t.errRegisterInvalid,
        'network_error' => t.errorNetwork,
        _ => t.errorGeneric,
      };

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    return Scaffold(
      appBar: AppBar(title: Text(t.registerTitle)),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextField(controller: _name, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: t.yourName)),
                  const SizedBox(height: 12),
                  TextField(controller: _business, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: t.businessName)),
                  const SizedBox(height: 12),
                  TextField(controller: _identifier, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: t.identifierLabel)),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _password,
                    obscureText: true,
                    onSubmitted: (_) => _submit(),
                    decoration: InputDecoration(labelText: t.passwordLabel, helperText: t.passwordHint),
                  ),
                  ExpansionTile(
                    tilePadding: EdgeInsets.zero,
                    title: Text(t.serverSettings),
                    children: [TextField(controller: _server, keyboardType: TextInputType.url, decoration: InputDecoration(labelText: t.serverLabel))],
                  ),
                  if (_errorCode != null) ...[
                    const SizedBox(height: 12),
                    Text(_message(t, _errorCode!), style: const TextStyle(color: AppColors.error)),
                  ],
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: _loading ? null : _submit,
                    child: _loading
                        ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : Text(t.registerButton),
                  ),
                  const SizedBox(height: 8),
                  TextButton(onPressed: _loading ? null : () => context.pop(), child: Text(t.haveAccount)),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
