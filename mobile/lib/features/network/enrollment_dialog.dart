import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../localization/app_localizations.dart';
import '../common/widgets.dart';

/// Affiche le code d'enrôlement renvoyé par l'API (`data.enrollment.code`). Il n'est montré qu'une fois :
/// l'interface le dit clairement et propose de le copier.
Future<void> showEnrollmentDialog(BuildContext context, Map<String, dynamic> router) {
  final enrollment = router['enrollment'];
  final code = enrollment is Map ? enrollment['code'] as String? : null;
  if (code == null) return Future.value();

  final t = AppLocalizations.of(context)!;
  return showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (ctx) => AlertDialog(
      title: Text(t.enrollCodeTitle),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SelectableText(code, style: const TextStyle(fontFamily: 'monospace', fontSize: 20, fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          Text(t.enrollCodeHelp),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () async {
            await Clipboard.setData(ClipboardData(text: code));
            if (ctx.mounted) showMessage(ctx, t.copied);
          },
          child: Text(t.copy),
        ),
        FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(t.close)),
      ],
    ),
  );
}
