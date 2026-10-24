/// Unités mineures par devise. Alimenté par le catalogue de l'API (`GET /currencies`) ; tant qu'il n'est pas chargé,
/// repli sur les devises connues sans subdivision (XOF, XAF...) et 2 décimales pour les autres.
class CurrencyFormats {
  CurrencyFormats._();

  static const _zeroDecimal = {'XOF', 'XAF', 'GNF', 'RWF', 'UGX', 'JPY', 'KRW'};
  static final Map<String, int> _minor = {};
  static final Map<String, String> _symbol = {};

  static void update(Iterable<Map<dynamic, dynamic>> catalog) {
    for (final c in catalog) {
      final code = (c['code'] as String).toUpperCase();
      final unit = c['minor_unit'];
      if (unit is int) _minor[code] = unit.clamp(0, 4);
      if (c['symbol'] is String) _symbol[code] = c['symbol'] as String;
    }
  }

  static int minorUnit(String currency) {
    final code = currency.toUpperCase();
    return _minor[code] ?? (_zeroDecimal.contains(code) ? 0 : 2);
  }

  static void clearForTests() {
    _minor.clear();
    _symbol.clear();
  }
}

String _grouped(int n) {
  final s = n.abs().toString();
  final buf = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) buf.write(' ');
    buf.write(s[i]);
  }
  return '${n < 0 ? '-' : ''}$buf';
}

/// Montant en unités mineures -> texte (ex. 12500 XOF -> « 12 500 XOF », 1050 USD -> « 10.50 USD »).
/// Chiffres latins dans toutes les langues (choix assumé : tickets, caisse et reçus restent comparables).
String formatMoney(num amount, String currency) {
  final a = amount.toInt();
  final decimals = CurrencyFormats.minorUnit(currency);
  if (decimals == 0) return '${_grouped(a)} $currency';
  final factor = _pow10(decimals);
  final major = a.abs() ~/ factor;
  final minor = (a.abs() % factor).toString().padLeft(decimals, '0');
  return '${a < 0 ? '-' : ''}${_grouped(major)}.$minor $currency';
}

int _pow10(int n) {
  var r = 1;
  for (var i = 0; i < n; i++) {
    r *= 10;
  }
  return r;
}

/// Saisie en unités majeures (« 10,5 » ou « 10.50 ») -> unités mineures (1050). Null si invalide :
/// pas de séparateur de milliers, pas plus de décimales que la devise n'en a, jamais de calcul en flottant.
int? parseMajorToMinor(String input, String currency) {
  final decimals = CurrencyFormats.minorUnit(currency);
  final text = input.trim().replaceAll(',', '.');
  final match = RegExp(r'^(\d{1,12})(?:\.(\d+))?$').firstMatch(text);
  if (match == null) return null;
  final frac = match.group(2) ?? '';
  if (frac.length > decimals) return null;
  final padded = frac.padRight(decimals, '0');
  return int.parse(match.group(1)! + padded);
}

String two(int n) => n.toString().padLeft(2, '0');

String isoDate(DateTime d) => '${d.year}-${two(d.month)}-${two(d.day)}';

/// « 2026-10-12T14:05:00+01:00 » -> « 12/10 14:05 » (heure locale de l'appareil).
String shortDateTime(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  return d == null ? '' : '${two(d.day)}/${two(d.month)} ${two(d.hour)}:${two(d.minute)}';
}
