import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

/// Nom de fichier sûr : lettres, chiffres, point, tiret, souligné uniquement (jamais de chemin).
String safeFileName(String name) {
  final cleaned = name.replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');
  return cleaned.isEmpty ? 'document' : cleaned;
}

/// Enregistre localement (dossier temporaire de l'application) et renvoie le chemin.
Future<String> saveLocally(List<int> bytes, String fileName) async {
  final dir = await getTemporaryDirectory();
  final file = File('${dir.path}/${safeFileName(fileName)}');
  await file.writeAsBytes(bytes, flush: true);
  return file.path;
}

/// Ouvre la feuille de partage du système (WhatsApp, e-mail, Drive...).
Future<void> shareFile(List<int> bytes, String fileName, String mimeType) async {
  final path = await saveLocally(bytes, fileName);
  await Share.shareXFiles([XFile(path, mimeType: mimeType)], subject: safeFileName(fileName));
}

/// Impression système (imprimante réseau, Bluetooth, « Enregistrer en PDF »).
Future<void> printPdf(List<int> bytes, String name) async {
  await Printing.layoutPdf(name: safeFileName(name), onLayout: (_) async => Uint8List.fromList(bytes));
}
