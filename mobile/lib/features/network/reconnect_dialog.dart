import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import 'network_providers.dart';

/// Reconnecte un routeur en connexion directe avec de nouveaux identifiants admin (mot de passe changé, compte dédié supprimé).
/// Les identifiants ne servent qu'à cet appel : le serveur recrée son compte limité et ne conserve pas l'admin.
Future<void> showReconnectDialog(BuildContext context, WidgetRef ref, String routerId, String host) async {
  final t = AppLocalizations.of(context)!;
  final user = TextEditingController(text: 'admin');
  final password = TextEditingController();
  var busy = false;

  await showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setState) => AlertDialog(
        title: Text(t.routerReconnect),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (host.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(host)),
            TextField(controller: user, autocorrect: false, decoration: InputDecoration(labelText: t.routerAdminUser)),
            const SizedBox(height: 8),
            TextField(
              controller: password,
              obscureText: true,
              autocorrect: false,
              enableSuggestions: false,
              decoration: InputDecoration(labelText: t.routerAdminPassword),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: busy ? null : () => Navigator.pop(ctx), child: Text(t.cancel)),
          FilledButton(
            onPressed: busy
                ? null
                : () async {
                    if (user.text.trim().isEmpty || password.text.isEmpty) {
                      showMessage(ctx, t.errValidation);
                      return;
                    }
                    setState(() => busy = true);
                    try {
                      await ref.read(apiClientProvider).post('/routers/$routerId/reconnect', body: {
                        'username': user.text.trim(),
                        'password': password.text,
                      });
                      ref.invalidate(routersProvider);
                      if (ctx.mounted) Navigator.pop(ctx);
                      if (context.mounted) showMessage(context, t.routerConnected);
                    } catch (e) {
                      if (ctx.mounted) {
                        setState(() => busy = false);
                        showMessage(ctx, errorText(t, e));
                      }
                    }
                  },
            child: Text(t.routerReconnect),
          ),
        ],
      ),
    ),
  );
  user.dispose();
  password.dispose();
}
