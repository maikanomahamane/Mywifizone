import 'package:flutter_test/flutter_test.dart';
import 'package:mywifizone/features/documents/document_actions.dart';

void main() {
  test('safeFileName supprime tout caractère de chemin', () {
    expect(safeFileName('../../etc/passwd'), '.._.._etc_passwd');
    expect(safeFileName('tickets 1.pdf'), 'tickets_1.pdf');
    expect(safeFileName(''), 'document');
  });
}
