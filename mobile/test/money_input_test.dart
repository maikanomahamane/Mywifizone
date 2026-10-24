import 'package:flutter_test/flutter_test.dart';
import 'package:mywifizone/features/common/format.dart';

void main() {
  tearDown(CurrencyFormats.clearForTests);

  test('saisie en unités majeures -> unités mineures, sans flottant', () {
    expect(parseMajorToMinor('200', 'XOF'), 200);
    expect(parseMajorToMinor('10,5', 'USD'), 1050);
    expect(parseMajorToMinor('10.05', 'EUR'), 1005);
    expect(parseMajorToMinor('0.1', 'USD'), 10);
  });

  test('saisies refusées', () {
    expect(parseMajorToMinor('10.5', 'XOF'), isNull);
    expect(parseMajorToMinor('10.555', 'USD'), isNull);
    expect(parseMajorToMinor('-5', 'USD'), isNull);
    expect(parseMajorToMinor('1 000', 'XOF'), isNull);
    expect(parseMajorToMinor('', 'XOF'), isNull);
    expect(parseMajorToMinor('abc', 'USD'), isNull);
  });

  test('le catalogue de l\'API fixe les décimales (devise ajoutée à 3 décimales)', () {
    CurrencyFormats.update([
      {'code': 'KWD', 'minor_unit': 3, 'symbol': 'KD'},
      {'code': 'XOF', 'minor_unit': 0, 'symbol': 'FCFA'},
    ]);
    expect(formatMoney(12345, 'KWD'), '12.345 KWD');
    expect(parseMajorToMinor('1.5', 'KWD'), 1500);
    expect(formatMoney(5000, 'XOF'), '5 000 XOF');
  });
}
