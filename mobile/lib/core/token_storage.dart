import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Les jetons ne sont stockés que dans le secure storage (Keychain / Keystore).
class TokenStorage {
  TokenStorage([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static const _access = 'access_token';
  static const _refresh = 'refresh_token';
  static const _server = 'server_url';

  Future<String?> get accessToken => _storage.read(key: _access);
  Future<String?> get refreshToken => _storage.read(key: _refresh);

  Future<void> save({required String access, required String refresh}) async {
    await _storage.write(key: _access, value: access);
    await _storage.write(key: _refresh, value: refresh);
  }

  /// Adresse de l'API choisie par l'utilisateur (publique : pas un secret, mais propre à l'appareil).
  Future<String?> get serverUrl => _storage.read(key: _server);
  Future<void> saveServerUrl(String url) => _storage.write(key: _server, value: url);

  Future<void> clear() async {
    await _storage.delete(key: _access);
    await _storage.delete(key: _refresh);
  }
}
