import 'package:go_router/go_router.dart';

import '../features/auth/login_page.dart';
import '../features/auth/register_page.dart';
import '../features/shell/app_shell.dart';

final appRouter = GoRouter(
  initialLocation: '/login',
  routes: [
    GoRoute(path: '/login', builder: (_, __) => const LoginPage()),
    GoRoute(path: '/register', builder: (_, __) => const RegisterPage()),
    GoRoute(path: '/home', builder: (_, __) => const AppShell()),
  ],
);
