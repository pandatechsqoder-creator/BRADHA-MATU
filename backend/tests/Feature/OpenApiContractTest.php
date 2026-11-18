<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    public function test_every_registered_api_operation_is_documented_and_vice_versa(): void
    {
        $documented = $this->documentedOperations();
        $registered = $this->registeredOperations();

        $this->assertSame([], array_values(array_diff($registered, $documented)), 'Registered API operations missing from openapi.yaml');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'openapi.yaml documents operations that are not registered');
    }

    /** @return list<string> */
    private function documentedOperations(): array
    {
        $path = base_path('../docs/api/openapi.yaml');
        $this->assertFileExists($path);

        $operations = [];
        $current = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('#^  (/.+):$#', $line, $match)) {
                $current = $this->normalise($match[1]);
                continue;
            }
            if ($current !== null && preg_match('#^    (get|post|put|patch|delete):$#', $line, $match)) {
                $operations[strtoupper($match[1]).' '.$current] = true;
            }
        }

        return array_keys($operations);
    }

    /** @return list<string> */
    private function registeredOperations(): array
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = $this->normalise(substr($route->uri(), strlen('api/v1')));
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[$method.' '.$path] = true;
                }
            }
        }

        return array_keys($operations);
    }

    private function normalise(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
