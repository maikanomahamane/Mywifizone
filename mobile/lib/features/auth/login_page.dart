import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/api_config.dart';
import '../../core/providers.dart';
import '../../design_system/app_colors.dart';
import '../../localization/app_localizations.dart';

class LoginPage extends ConsumerStatefulWidget {
  const LoginPage({super.key});

  @override
  ConsumerState<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends ConsumerState<LoginPage> {
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

  /// Adresse de l'API mémorisée lors d'une précédente connexion (utile pour tester sur un réseau local).
  Future<void> _loadServer() async {
    final saved = await ref.read(tokenStorageProvider).serverUrl;
    if (saved != null && saved.isNotEmpty && mounted) {
      _server.text = saved;
      ref.read(apiClientProvider).setBaseUrl(saved);
    }
  }

  @override
  void dispose() {
    _identifier.dispose();
    _password.dispose();
    _server.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
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
      final user = await api.login(_identifier.text.trim(), _password.text);
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
        'invalid_credentials' => t.errorInvalidCredentials,
        'tenant_suspended' => t.errorTenantSuspended,
        'network_error' => t.errorNetwork,
        _ => t.errorGeneric,
      };

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(t.appName,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w800,
                          color: AppColors.primary)),
                  const SizedBox(height: 4),
                  Text(t.tagline, textAlign: TextAlign.center),
                  const SizedBox(height: 32),
                  Text(t.loginTitle,
                      style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _identifier,
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.username],
                    decoration: InputDecoration(labelText: t.identifierLabel),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _password,
                    obscureText: true,
                    autofillHints: const [AutofillHints.password],
                    onSubmitted: (_) => _submit(),
                    decoration: InputDecoration(labelText: t.passwordLabel),
                  ),
                  ExpansionTile(
                    tilePadding: EdgeInsets.zero,
                    title: Text(t.serverSettings),
                    children: [
                      TextField(
                        controller: _server,
                        keyboardType: TextInputType.url,
                        decoration: InputDecoration(labelText: t.serverLabel),
                      ),
                    ],
                  ),
                  if (_errorCode != null) ...[
                    const SizedBox(height: 12),
                    Text(_message(t, _errorCode!),
                        style: const TextStyle(color: AppColors.error)),
                  ],
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: _loading ? null : _submit,
                    child: _loading
                        ? const SizedBox(
                            height: 22,
                            width: 22,
                            child: CircularProgressIndicator(
                                strokeWidth: 2, color: Colors.white))
                        : Text(t.loginButton),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
