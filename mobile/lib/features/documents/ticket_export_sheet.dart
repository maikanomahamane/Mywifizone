import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api_client.dart';
import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import 'document_actions.dart';

const exportFormats = ['a4', 'a5', 'individual', 'thermal'];
const documentLocales = ['fr', 'en', 'ar', 'ha'];

String exportFormatLabel(AppLocalizations t, String f) => switch (f) {
      'a4' => t.fmtA4,
      'a5' => t.fmtA5,
      'individual' => t.fmtIndividual,
      _ => t.fmtThermal,
    };

/// Génère le PDF des tickets disponibles d'un routeur et d'un profil, puis le partage ou l'imprime.
/// Le PDF reste dans le dossier temporaire de l'application ; il contient des codes de connexion : le partager avec soin.
class TicketExportSheet extends ConsumerStatefulWidget {
  const TicketExportSheet({super.key});

  @override
  ConsumerState<TicketExportSheet> createState() => _TicketExportSheetState();
}

class _TicketExportSheetState extends ConsumerState<TicketExportSheet> {
  String? _routerId;
  String? _profileId;
  String _format = 'a4';
  int _thermalWidth = 58;
  String? _locale;
  bool _qr = false;
  bool _busy = false;
  DownloadedFile? _file;
  String _fileName = 'tickets.pdf';

  Future<void> _generate() async {
    final t = AppLocalizations.of(context)!;
    if (_routerId == null || _profileId == null) {
      showMessage(context, t.errValidation);
      return;
    }
    setState(() {
      _busy = true;
      _file = null;
    });
    try {
      final locale = _locale ?? ref.read(localeProvider).languageCode;
      final file = await ref.read(apiClientProvider).download('POST', '/tickets/pdf', body: {
        'format': _format,
        if (_format == 'thermal') 'thermal_width': _thermalWidth,
        'locale': locale,
        'router_id': _routerId,
        'profile_id': _profileId,
        'include_qr': _qr,
      });
      _fileName = 'tickets-${DateTime.now().millisecondsSinceEpoch}.pdf';
      if (mounted) setState(() => _file = file);
    } catch (e) {
      if (mounted) showMessage(context, errorText(t, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    final routers = ref.watch(routersProvider).valueOrNull ?? const <Json>[];
    final profiles = ref.watch(profilesProvider).valueOrNull ?? const <Json>[];
    final locale = _locale ?? ref.watch(localeProvider).languageCode;

    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(t.exportTickets, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 4),
            Text(t.exportPickHint, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: _routerId,
              isExpanded: true,
              decoration: InputDecoration(labelText: t.batchRouter),
              items: [for (final r in routers) DropdownMenuItem(value: r['id'] as String, child: Text('${r['name']}'))],
              onChanged: (v) => setState(() => _routerId = v),
            ),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              value: _profileId,
              isExpanded: true,
              decoration: InputDecoration(labelText: t.batchProfile),
              items: [for (final p in profiles) DropdownMenuItem(value: p['id'] as String, child: Text('${p['name']}'))],
              onChanged: (v) => setState(() => _profileId = v),
            ),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              value: _format,
              decoration: InputDecoration(labelText: t.exportFormat),
              items: [for (final f in exportFormats) DropdownMenuItem(value: f, child: Text(exportFormatLabel(t, f)))],
              onChanged: (v) => setState(() => _format = v ?? 'a4'),
            ),
            if (_format == 'thermal') ...[
              const SizedBox(height: 8),
              SegmentedButton<int>(
                segments: const [ButtonSegment(value: 58, label: Text('58 mm')), ButtonSegment(value: 80, label: Text('80 mm'))],
                selected: {_thermalWidth},
                onSelectionChanged: (s) => setState(() => _thermalWidth = s.first),
              ),
            ],
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              value: locale,
              decoration: InputDecoration(labelText: t.docLanguage),
              items: const [
                DropdownMenuItem(value: 'fr', child: Text('Français')),
                DropdownMenuItem(value: 'en', child: Text('English')),
                DropdownMenuItem(value: 'ar', child: Text('العربية')),
                DropdownMenuItem(value: 'ha', child: Text('Hausa')),
              ],
              onChanged: (v) => setState(() => _locale = v),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(t.includeQr),
              subtitle: Text(t.qrNeedsPlan),
              value: _qr,
              onChanged: (v) => setState(() => _qr = v),
            ),
            const SizedBox(height: 4),
            FilledButton(
              onPressed: _busy ? null : _generate,
              child: _busy ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Text(t.exportGenerate),
            ),
            if (_file != null) ...[
              const SizedBox(height: 12),
              Text(t.exportReady(_file!.count ?? 0), textAlign: TextAlign.center),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.share),
                      label: Text(t.exportShare),
                      onPressed: () => shareFile(_file!.bytes, _fileName, 'application/pdf'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.print),
                      label: Text(t.exportPrint),
                      onPressed: () => printPdf(_file!.bytes, _fileName),
                    ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
