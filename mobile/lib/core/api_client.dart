import 'dart:convert';

import 'package:dio/dio.dart';

import 'api_config.dart';
import 'token_storage.dart';

/// Erreur API normalisée : [code] est la clé stable renvoyée par le backend
/// (errors[].code) que l'UI traduit via l'i18n.
class ApiException implements Exception {
  ApiException(this.code, {this.status});
  final String code;
  final int? status;

  @override
  String toString() => 'ApiException($code, $status)';
}

/// Contenu de l'enveloppe `{ success, data, meta, errors }`.
class ApiResult {
  ApiResult(this.data, this.meta);
  final dynamic data;
  final Map<String, dynamic> meta;

  Map<String, dynamic> get map => Map<String, dynamic>.from(data as Map);

  List<Map<String, dynamic>> get list =>
      (data as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
}

/// Fichier binaire reçu (PDF, CSV).
class DownloadedFile {
  DownloadedFile(this.bytes, this.count);
  final List<int> bytes;

  /// En-tête `X-Tickets-Count` (nombre de tickets du PDF), sinon null.
  final int? count;
}

class ApiClient {
  ApiClient(this._tokens, {Dio? dio})
      : dio = dio ??
            Dio(BaseOptions(
              baseUrl: apiBaseUrl,
              connectTimeout: const Duration(seconds: 10),
              receiveTimeout: const Duration(seconds: 20),
              headers: {'Accept': 'application/json'},
            )) {
    this.dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) async {
      final token = await _tokens.accessToken;
      if (token != null) options.headers['Authorization'] = 'Bearer $token';
      handler.next(options);
    }));
    // Refresh automatique sur 401 : client Dio dédié (sans intercepteurs) pour ne pas se rappeler soi-même.
    this.dio.interceptors.add(_RefreshInterceptor(_tokens, this.dio, Dio(this.dio.options.copyWith())));
  }

  final TokenStorage _tokens;
  final Dio dio;

  /// Change l'adresse de l'API à l'exécution (test sur un réseau local, serveur de recette...).
  void setBaseUrl(String url) {
    dio.options.baseUrl = url.trim().replaceAll(RegExp(r'/+$'), '');
  }

  /// Appel générique : renvoie `data` et `meta` de l'enveloppe, ou lève [ApiException] (code stable du backend).
  Future<ApiResult> request(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Object? body,
  }) async {
    try {
      final res = await dio.request<dynamic>(
        path,
        data: body,
        queryParameters: query,
        options: Options(method: method),
      );
      final json = res.data;
      if (json is! Map) throw ApiException('server_error', status: res.statusCode);
      final meta = json['meta'];
      return ApiResult(json['data'], meta is Map ? Map<String, dynamic>.from(meta) : <String, dynamic>{});
    } on DioException catch (e) {
      throw _toApiException(e);
    }
  }

  Future<ApiResult> get(String path, {Map<String, dynamic>? query}) => request('GET', path, query: query);
  Future<ApiResult> post(String path, {Object? body}) => request('POST', path, body: body ?? <String, dynamic>{});
  Future<ApiResult> put(String path, {Object? body}) => request('PUT', path, body: body);
  Future<ApiResult> delete(String path) => request('DELETE', path);

  /// Télécharge un fichier (PDF, CSV). En cas d'erreur, le corps JSON de l'enveloppe est décodé : [ApiException] porte le code stable.
  Future<DownloadedFile> download(String method, String path, {Map<String, dynamic>? query, Object? body}) async {
    try {
      final res = await dio.request<List<int>>(
        path,
        data: body,
        queryParameters: query,
        options: Options(
          method: method,
          responseType: ResponseType.bytes,
          receiveTimeout: const Duration(seconds: 90),
          headers: {'Accept': '*/*'},
        ),
      );
      return DownloadedFile(res.data ?? const <int>[], int.tryParse(res.headers.value('x-tickets-count') ?? ''));
    } on DioException catch (e) {
      final raw = e.response?.data;
      if (raw is List<int>) {
        try {
          final decoded = jsonDecode(utf8.decode(raw));
          if (decoded is Map && decoded['errors'] is List && (decoded['errors'] as List).isNotEmpty) {
            final first = (decoded['errors'] as List).first;
            if (first is Map && first['code'] is String) {
              return Future.error(ApiException(first['code'] as String, status: e.response?.statusCode));
            }
          }
        } catch (_) {
          // corps non JSON : on retombe sur l'erreur générique ci-dessous
        }
      }
      throw ApiException(e.response == null ? 'network_error' : 'server_error', status: e.response?.statusCode);
    }
  }

  /// Connexion. Stocke les jetons et renvoie le profil utilisateur.
  Future<Map<String, dynamic>> login(String identifier, String password) async {
    try {
      final res = await dio.post('/auth/login', data: {
        'identifier': identifier,
        'password': password,
        'device_name': 'mobile',
      });
      final data = res.data['data'] as Map<String, dynamic>;
      final tokens = data['tokens'] as Map<String, dynamic>;
      await _tokens.save(
        access: tokens['access_token'] as String,
        refresh: tokens['refresh_token'] as String,
      );
      return data['user'] as Map<String, dynamic>;
    } on DioException catch (e) {
      throw _toApiException(e);
    }
  }

  /// Inscription d'un nouveau propriétaire (crée le tenant et l'essai). Stocke les jetons et renvoie le profil.
  Future<Map<String, dynamic>> register({
    required String name,
    required String businessName,
    required String identifier,
    required String password,
  }) async {
    try {
      final isEmail = identifier.contains('@');
      final res = await dio.post('/auth/register', data: {
        'name': name,
        'business_name': businessName,
        if (isEmail) 'email': identifier else 'phone': identifier,
        'password': password,
        'device_name': 'mobile',
      });
      final data = res.data['data'] as Map<String, dynamic>;
      final tokens = data['tokens'] as Map<String, dynamic>;
      await _tokens.save(
        access: tokens['access_token'] as String,
        refresh: tokens['refresh_token'] as String,
      );
      return data['user'] as Map<String, dynamic>;
    } on DioException catch (e) {
      throw _toApiException(e);
    }
  }

  ApiException _toApiException(DioException e) {
    final body = e.response?.data;
    if (body is Map && body['errors'] is List && (body['errors'] as List).isNotEmpty) {
      final first = (body['errors'] as List).first;
      if (first is Map && first['code'] is String) {
        return ApiException(first['code'] as String, status: e.response?.statusCode);
      }
    }
    return ApiException(e.response == null ? 'network_error' : 'server_error',
        status: e.response?.statusCode);
  }
}

/// Sur 401 (jeton d'accès expiré) : échange le refresh token, puis rejoue UNE fois la requête.
/// `QueuedInterceptor` sérialise les erreurs : plusieurs requêtes qui échouent en même temps ne lancent
/// pas plusieurs refresh (le backend révoque toute la famille de jetons si un refresh token est rejoué).
/// Si le refresh est refusé (401), la session est terminée et les jetons sont effacés.
class _RefreshInterceptor extends QueuedInterceptor {
  _RefreshInterceptor(this._tokens, this._dio, this._refreshDio);

  final TokenStorage _tokens;
  final Dio _dio;
  final Dio _refreshDio;

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    final request = err.requestOptions;

    if (err.response?.statusCode != 401 ||
        request.path.startsWith('/auth/') ||
        request.extra['retried'] == true) {
      return handler.next(err);
    }

    final refresh = await _tokens.refreshToken;
    if (refresh == null) return handler.next(err);

    try {
      _refreshDio.options.baseUrl = _dio.options.baseUrl; // l'adresse peut avoir changé depuis la création
      final res = await _refreshDio.post('/auth/refresh', data: {'refresh_token': refresh});
      final tokens = res.data['data']['tokens'] as Map<String, dynamic>;
      await _tokens.save(
        access: tokens['access_token'] as String,
        refresh: tokens['refresh_token'] as String,
      );

      request.extra['retried'] = true;
      request.headers['Authorization'] = 'Bearer ${tokens['access_token']}';
      return handler.resolve(await _dio.fetch(request));
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) await _tokens.clear();
      return handler.next(err);
    }
  }
}
