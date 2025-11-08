<?php

/**
 * TypeScript Client Generator
 *
 * Generates a TypeScript client from PHP routes with full type safety.
 *
 * Usage:
 *   php generate client [--output=<path>]
 *
 * Example:
 *   php generate client
 *   php generate client --output=frontend/src/api/client.ts
 */

// Determine the root path (go up two levels from Framework/cli)
define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

// Auto-load Framework and App classes
spl_autoload_register(function ($class) {
    // Try loading from root (for Framework\... and App\...)
    $file = ROOT_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    // Try loading from src (for App\...)
    $file = SRC_PATH . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }
});

// Parse command line arguments
$outputPath = 'client/api.ts';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, 9);
    }
    if (in_array($arg, ['--help', '-h', 'help'])) {
        echo "TypeScript Client Generator\n";
        echo "============================\n\n";
        echo "Usage: php generate client [--output=<path>]\n\n";
        echo "Options:\n";
        echo "  --output=<path>  Output file path (default: client/api.ts)\n\n";
        echo "Example:\n";
        echo "  php generate client\n";
        echo "  php generate client --output=frontend/src/api/client.ts\n";
        exit(0);
    }
}

// Convert to absolute path if relative
if (!str_starts_with($outputPath, '/')) {
    $outputPath = ROOT_PATH . $outputPath;
}

/**
 * Load and parse routes from routes.php
 */
function loadRoutes(): array {
    $routesFile = SRC_PATH . 'config' . DIRECTORY_SEPARATOR . 'routes.php';

    if (!file_exists($routesFile)) {
        echo "Error: routes.php not found at $routesFile\n";
        exit(1);
    }

    $routes = require $routesFile;

    // Flatten routes by method
    $flatRoutes = [];
    foreach ($routes as $method => $methodRoutes) {
        foreach ($methodRoutes as $path => $handler) {
            $flatRoutes[] = [
                'method' => $method,
                'path' => $path,
                'handler' => $handler
            ];
        }
    }

    return $flatRoutes;
}

/**
 * Get the contract interface for a route handler
 */
function getRouteContract(string $handlerClass): ?ReflectionClass {
    if (!class_exists($handlerClass)) {
        return null;
    }

    $reflection = new ReflectionClass($handlerClass);
    $interfaces = $reflection->getInterfaces();

    // Find the contract interface (not IRouteHandler)
    foreach ($interfaces as $interface) {
        if ($interface->getName() !== 'Framework\\IRouteHandler') {
            return $interface;
        }
    }

    return null;
}

/**
 * Extract request and response types from interface
 */
function extractContractTypes(ReflectionClass $interface): ?array {
    if (!$interface->hasMethod('execute')) {
        return null;
    }

    $method = $interface->getMethod('execute');
    $params = $method->getParameters();

    if (empty($params)) {
        return null;
    }

    $requestType = $params[0]->getType();
    $responseType = $method->getReturnType();

    if (!$requestType || !$responseType) {
        return null;
    }

    return [
        'request' => $requestType->getName(),
        'response' => $responseType->getName()
    ];
}

/**
 * PHP type to TypeScript type mapping
 */
function phpTypeToTs(string $phpType): string {
    return match ($phpType) {
        'int', 'integer', 'float', 'double' => 'number',
        'bool', 'boolean' => 'boolean',
        'string' => 'string',
        'array' => 'any[]',
        'mixed' => 'any',
        default => $phpType // Keep class names as-is for now
    };
}

/**
 * Reflect on a DTO class and extract its properties with types
 */
function reflectDto(string $className): array {
    if (!class_exists($className)) {
        return [];
    }

    $reflection = new ReflectionClass($className);
    $constructor = $reflection->getConstructor();

    if (!$constructor) {
        return [];
    }

    $properties = [];
    foreach ($constructor->getParameters() as $param) {
        $type = $param->getType();
        $typeName = $type ? $type->getName() : 'any';
        $isNullable = $type && $type->allowsNull();

        // Check if this is a class type (nested DTO)
        $isClassType = $type && !$type->isBuiltin() && class_exists($typeName);

        $properties[] = [
            'name' => $param->getName(),
            'type' => $typeName,
            'nullable' => $isNullable,
            'isClass' => $isClassType,
            'optional' => $param->isOptional()
        ];
    }

    return $properties;
}

/**
 * Generate TypeScript interface from DTO
 */
function generateTsInterface(string $className, array &$processedClasses = []): string {
    // Avoid infinite recursion
    if (in_array($className, $processedClasses)) {
        return '';
    }
    $processedClasses[] = $className;

    $properties = reflectDto($className);
    if (empty($properties)) {
        return '';
    }

    $shortName = substr($className, strrpos($className, '\\') + 1);
    $output = "export interface $shortName {\n";

    $nestedInterfaces = '';

    foreach ($properties as $prop) {
        $tsType = phpTypeToTs($prop['type']);

        // If this is a nested class, generate its interface too
        if ($prop['isClass']) {
            $nestedInterfaces .= generateTsInterface($prop['type'], $processedClasses);
            $tsType = substr($prop['type'], strrpos($prop['type'], '\\') + 1);
        }

        $optional = $prop['optional'] ? '?' : '';
        $nullable = $prop['nullable'] ? ' | null' : '';

        $output .= "  {$prop['name']}$optional: $tsType$nullable;\n";
    }

    $output .= "}\n\n";

    return $nestedInterfaces . $output;
}

/**
 * Convert route path to function name
 * /login -> login
 * /items/{itemId}/view -> getItemView
 * /users/{userId}/posts -> getUserPosts
 */
function pathToFunctionName(string $path, string $method): string {
    // Remove leading/trailing slashes
    $path = trim($path, '/');

    // Split by /
    $parts = explode('/', $path);

    // Remove parameter parts
    $parts = array_filter($parts, fn($part) => !preg_match('/^\{.+\}$/', $part));

    // Convert to camelCase
    $functionName = '';
    foreach ($parts as $i => $part) {
        $part = str_replace(['-', '_'], ' ', $part);
        $part = ucwords($part);
        $part = str_replace(' ', '', $part);

        if ($i === 0) {
            $part = lcfirst($part);
        }

        $functionName .= $part;
    }

    // If empty, use a default based on method
    if (empty($functionName)) {
        $functionName = strtolower($method);
    }

    // Prefix with method for non-GET requests
    if ($method !== 'GET' && !str_starts_with($functionName, strtolower($method))) {
        $functionName = strtolower($method) . ucfirst($functionName);
    }

    return $functionName;
}

/**
 * Extract path parameter names
 */
function extractPathParams(string $path): array {
    preg_match_all('/\{([^}]+)\}/', $path, $matches);
    return $matches[1] ?? [];
}

/**
 * Generate TypeScript API client
 */
function generateClient(array $routes): string {
    $interfaces = '';
    $functions = '';
    $processedClasses = [];

    foreach ($routes as $route) {
        $handlerClass = $route['handler'];
        $contract = getRouteContract($handlerClass);

        if (!$contract) {
            echo "Warning: No contract interface found for {$route['path']}\n";
            continue;
        }

        $types = extractContractTypes($contract);

        if (!$types) {
            echo "Warning: Could not extract types from contract for {$route['path']}\n";
            continue;
        }

        // Generate interfaces for request and response
        $interfaces .= generateTsInterface($types['request'], $processedClasses);
        $interfaces .= generateTsInterface($types['response'], $processedClasses);

        // Generate API function
        $functionName = pathToFunctionName($route['path'], $route['method']);
        $requestTypeName = substr($types['request'], strrpos($types['request'], '\\') + 1);
        $responseTypeName = substr($types['response'], strrpos($types['response'], '\\') + 1);

        $pathParams = extractPathParams($route['path']);
        $pathParamsStr = '';
        $pathParamsInUrl = '';

        if (!empty($pathParams)) {
            $pathParamsInUrl = $route['path'];
            foreach ($pathParams as $param) {
                $pathParamsInUrl = str_replace("{{$param}}", "\${$param}", $pathParamsInUrl);
            }
            $pathParamsInUrl = "`$pathParamsInUrl`";
        } else {
            $pathParamsInUrl = "'{$route['path']}'";
        }

        $method = strtoupper($route['method']);

        if ($method === 'GET') {
            // GET requests don't have a body, path params are the only params
            if (!empty($pathParams)) {
                $pathParamsStr = implode(': string, ', $pathParams) . ': string';
            }
            $functions .= <<<TS
  async $functionName($pathParamsStr): Promise<$responseTypeName> {
    const response = await fetch($pathParamsInUrl, {
      method: '$method',
      headers: {
        'Content-Type': 'application/json',
      },
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: \${response.status}`);
    }

    const json = await response.json();
    return json.data;
  },


TS;
        } else {
            // POST, PUT, DELETE, etc. have a body, path params come after
            if (!empty($pathParams)) {
                $pathParamsStr = ', ' . implode(': string, ', $pathParams) . ': string';
            }
            $functions .= <<<TS
  async $functionName(data: $requestTypeName$pathParamsStr): Promise<$responseTypeName> {
    const response = await fetch($pathParamsInUrl, {
      method: '$method',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: \${response.status}`);
    }

    const json = await response.json();
    return json.data;
  },


TS;
        }
    }

    $output = <<<TS
/**
 * Auto-generated TypeScript API Client
 * Generated from PHP routes
 *
 * DO NOT EDIT MANUALLY - Regenerate using: php generate client
 */

// ============================================================================
// Type Definitions
// ============================================================================

$interfaces
// ============================================================================
// API Client
// ============================================================================

export const api = {
$functions};

export default api;

TS;

    return $output;
}

// Main execution
echo "Scanning routes...\n";
$routes = loadRoutes();
echo "Found " . count($routes) . " route(s)\n";

echo "Generating TypeScript client...\n";
$clientCode = generateClient($routes);

// Create output directory if needed
$outputDir = dirname($outputPath);
if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0755, true)) {
        echo "Error: Failed to create output directory: $outputDir\n";
        exit(1);
    }
}

file_put_contents($outputPath, $clientCode);

echo "✓ Generated TypeScript client: $outputPath\n";
echo "\nUsage in your frontend:\n";
echo "  import { api } from './api';\n";
echo "  const result = await api.functionName(data);\n";
