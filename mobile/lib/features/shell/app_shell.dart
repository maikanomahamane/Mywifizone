import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../localization/app_localizations.dart';
import '../home/home_page.dart';
import '../international/international_providers.dart';
import '../more/more_page.dart';
import '../network/network_page.dart';
import '../sales/sales_page.dart';
import '../tickets/tickets_page.dart';

/// Barre de navigation à 5 onglets (cahier des charges §3) : Accueil, Tickets, Ventes, Réseau, Plus.
class AppShell extends ConsumerStatefulWidget {
  const AppShell({super.key});

  @override
  ConsumerState<AppShell> createState() => _AppShellState();
}

class _AppShellState extends ConsumerState<AppShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context)!;
    ref.watch(currenciesProvider); // charge le catalogue : unités mineures de chaque devise

    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: const [
          HomePage(),
          TicketsPage(),
          SalesPage(),
          NetworkPage(),
          MorePage(),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.home_outlined), selectedIcon: const Icon(Icons.home), label: t.navHome),
          NavigationDestination(icon: const Icon(Icons.confirmation_number_outlined), selectedIcon: const Icon(Icons.confirmation_number), label: t.navTickets),
          NavigationDestination(icon: const Icon(Icons.point_of_sale_outlined), selectedIcon: const Icon(Icons.point_of_sale), label: t.navSales),
          NavigationDestination(icon: const Icon(Icons.router_outlined), selectedIcon: const Icon(Icons.router), label: t.navNetwork),
          NavigationDestination(icon: const Icon(Icons.more_horiz), selectedIcon: const Icon(Icons.more_horiz), label: t.navMore),
        ],
      ),
    );
  }
}
