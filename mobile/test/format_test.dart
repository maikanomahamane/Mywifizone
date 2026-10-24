import 'package:flutter_test/flutter_test.dart';
import 'package:mywifizone/features/common/format.dart';

void main() {
  test('devises sans subdivision : le montant est affiché tel quel, par groupes de trois', () {
    expect(formatMoney(12500, 'XOF'), '12 500 XOF');
    expect(formatMoney(0, 'XOF'), '0 XOF');
    expect(formatMoney(-1500, 'XOF'), '-1 500 XOF');
    expect(formatMoney(2000000, 'xaf'), '2 000 000 xaf');
  });

  test('devises à deux décimales : unités mineures converties pour l\'affichage', () {
    expect(formatMoney(1050, 'USD'), '10.50 USD');
    expect(formatMoney(5, 'EUR'), '0.05 EUR');
    expect(formatMoney(123456, 'EUR'), '1 234.56 EUR');
  });

  test('dates', () {
    expect(isoDate(DateTime(2026, 10, 5)), '2026-10-05');
    expect(shortDateTime(null), '');
    expect(shortDateTime('pas une date'), '');
  });
}
