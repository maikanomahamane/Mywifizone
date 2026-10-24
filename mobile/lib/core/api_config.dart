/// URL de l'API injectée au build : flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080/api/v1
/// Aucun secret dans l'application mobile (seulement une URL publique).
const apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8080/api/v1',
);
