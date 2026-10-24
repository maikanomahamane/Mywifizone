import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/providers.dart';
import '../../localization/app_localizations.dart';
import '../common/widgets.dart';
import '../network/network_providers.dart';
import '../sales/sales_providers.dart';

final externalLinksProvider = FutureProvider.autoDispose<List<Json>>((ref) async => (await ref.watch(apiClientProvider).get('/external-links')).list);

/// Services externes (ex. Starlink) : ouvre le portail OFFICIEL dans le navigateur. L'application ne demande ni ne
/// conserve jamais d'identifiant ou de mot de passe, et n'appelle aucune API Starlink.
class ExternalLinksPage extends ConsumerWidget {
  const ExternalLinksPage({super.key});

  Future<void> _open(BuildContext context, String url) async {
    final t = AppLocalizations.of(context)!;
    final uri = Uri.tryParse(url);
    if (uri == null || uri.scheme != 'https') {
      showMessage(context, t.errorGeneric);
      return;
    }
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) showMessage(context, t.errorGeneric);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = AppLocalizations.of(context)!;
    final links = ref.watch(externalLinksProvider);
    final manage = can(ref, 'tenant.manage');

    return Scaffold(
      appBar: AppBar(title: Text(t.servicesTitle)),
      floatingActionButton: manage
          ? FloatingActionButton.extended(
              icon: const Icon(Icons.add),
              label: Text(t.serviceAdd),
              onPressed: () async {
                final saved = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, builder: (_) => const _LinkForm());
                if (saved == true) ref.invalidate(externalLinksProvider);
              },
            )
          : null,
      body: links.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(externalLinksProvider)),
        data: (list) => list.isEmpty
            ? EmptyView(t.servicesEmpty)
            : ListView(
                padding: const EdgeInsets.only(bottom: 88),
                children: [
                  Padding(padding: const EdgeInsets.all(16), child: Text(t.servicesNote, style: Theme.of(context).textTheme.bodySmall)),
                  for (final l in list.where((l) => l['is_active'] == true || manage))
                    ListTile(
                      leading: Icon(l['kind'] == 'STARLINK' ? Icons.satellite_alt : Icons.open_in_new),
                      title: Text(l['name'] as String),
                      subtitle: Text(Uri.tryParse(l['url'] as String)?.host ?? ''),
                      onTap: () => _open(context, l['url'] as String),
                      trailing: manage
                          ? IconButton(
                              tooltip: t.delete,
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                try {
                                  await ref.read(apiClientProvider).delete('/external-links/${l['id']}');
                                  ref.invalidate(externalLinksProvider);
                                } catch (e) {
                                  if (context.mounted) showMessage(context, errorText(t, e));
                                }
                              },
                            )
                          : null,
                    ),
                ],
              ),
      ),
    );
  }
}

class _LinkForm extends ConsumerStatefulWidget {
  const _LinkForm();

  @override
  ConsumerState<_LinkForm> createState() => _LinkFormState();
}

class _LinkFormState extends ConsumerState<_LinkForm> {
  final _name = TextEditingController();
  final _url = TextEditingController(text: 'https://');
  String _kind = 'STARLINK';
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _url.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final t = AppLocalizations.of(context)!;
    setState(() => _busy = true);
    try {
      await ref.read(apiClientProvider).post('/external-links', body: {'kind': _kind, 'name': _name.text.trim(), 'url': _url.text.trim()});
      if (mounted) Navigator.pop(context, true);
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
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(t.serviceAdd, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            segments: const [ButtonSegment(value: 'STARLINK', label: Text('Starlink')), ButtonSegment(value: 'OTHER', label: Text('…'))],
            selected: {_kind},
            onSelectionChanged: (s) => setState(() => _kind = s.first),
          ),
          TextField(controller: _name, maxLength: 80, decoration: InputDecoration(labelText: t.serviceName)),
          TextField(controller: _url, keyboardType: TextInputType.url, decoration: InputDecoration(labelText: t.serviceUrl, helperText: t.serviceUrlHelp, helperMaxLines: 3)),
          const SizedBox(height: 12),
          FilledButton(onPressed: _busy ? null : _save, child: Text(t.save)),
        ],
      ),
    );
  }
}
