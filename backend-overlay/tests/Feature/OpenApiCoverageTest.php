<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Garde-fou : le contrat OpenAPI (docs/openapi.yaml) doit décrire exactement les routes /api/v1.
 * Une route ajoutée sans documentation, ou une documentation orpheline, fait échouer la suite.
 */
class OpenApiCoverageTest extends TestCase
{
    /** @return array<int, string> "METHOD /chemin" documentés */
    private function documented(): array
    {
        $file = base_path('docs/openapi.yaml');
        $this->assertFileExists($file);

        $operations = [];
        $path = null;
        $inPaths = false;

        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^paths:\s*$/', $line)) {
                $inPaths = true;

                continue;
            }
            if ($inPaths && preg_match('/^\S/', $line)) { // fin de la section paths (components:)
                break;
            }
            if ($inPaths && preg_match('/^  (\/[^\s:]*):\s*$/', $line, $m)) {
                $path = $m[1];

                continue;
            }
            if ($inPaths && $path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $m)) {
                $operations[] = strtoupper($m[1]).' '.$path;
            }
        }

        sort($operations);

        return $operations;
    }

    /** @return array<int, string> */
    private function routed(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            $path = substr($route->uri(), strlen('api/v1')) ?: '/';
            foreach ($route->methods() as $method) {
                if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    $operations[] = $method.' '.$path;
                }
            }
        }

        sort($operations);

        return $operations;
    }

    public function test_every_route_is_documented_and_nothing_is_documented_twice_or_in_vain(): void
    {
        $documented = $this->documented();
        $routed = $this->routed();

        $this->assertNotEmpty($routed);
        $this->assertSame([], array_values(array_diff($routed, $documented)), 'Routes sans documentation OpenAPI');
        $this->assertSame([], array_values(array_diff($documented, $routed)), 'Opérations documentées mais inexistantes');
        $this->assertSame($documented, array_values(array_unique($documented)), 'Opération documentée en double');
    }
}
