<?php

/**
 * Route Generator
 *
 * Generates a new route handler class in the src/App/Routes directory.
 *
 * Usage:
 *   php generate route <route-name>
 *
 * Example:
 *   php generate route user-login
 *   This will create src/App/Routes/UserLoginRoute.php
 */

// Determine the root path (go up two levels from Framework/cli)
define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);

// Check for help flag
if ($argc === 1 || ($argc === 2 && in_array($argv[1], ['--help', '-h', 'help']))) {
    echo "Route Generator\n";
    echo "===============\n\n";
    echo "Usage: php generate route <route-name>\n\n";
    echo "Arguments:\n";
    echo "  route-name    Name of the route (use kebab-case, e.g., user-login)\n\n";
    echo "Example:\n";
    echo "  php generate route user-login\n";
    echo "  This will create src/App/Routes/UserLoginRoute.php\n";
    exit(0);
}

if ($argc !== 2) {
    echo "Error: Invalid number of arguments\n";
    echo "Usage: php generate route <route-name>\n";
    echo "Run 'php generate route --help' for more information.\n";
    exit(1);
}

// Convert route-name to RouteNameRoute class format
$classname = implode(array_map(fn($str) => ucfirst($str), explode('-', $argv[1]))) . 'Route';
$route_filename = $classname . '.php';
$routes_dir = SRC_PATH . 'App' . DIRECTORY_SEPARATOR . 'Routes';
$route_filepath = $routes_dir . DIRECTORY_SEPARATOR . $route_filename;

// Create src/App/Routes directory if it doesn't exist
if (!is_dir($routes_dir)) {
    if (!mkdir($routes_dir, 0755, true)) {
        echo "Error: Failed to create src/App/Routes directory\n";
        exit(1);
    }
    echo "Created src/App/Routes directory\n";
}

// Check if file already exists
if (file_exists($route_filepath)) {
    echo "Error: Route file already exists: $route_filepath\n";
    echo "Delete it first if you want to regenerate it.\n";
    exit(1);
}

$route_file_content = <<<EOD
<?php

namespace App\Routes;

use Framework\IRouteHandler;
use Framework\ApiResponse;

class $classname implements IRouteHandler
{
    public function validation_rules(): array
    {
        return [];
    }

    public function process(): ApiResponse
    {
        // TODO: Implement route logic
        // Example: return res_ok([], 'Success message');
        throw new \Exception('Not Implemented');
    }

}

EOD;

file_put_contents($route_filepath, $route_file_content);

echo "Created route: src/App/Routes/$route_filename\n";
echo "Namespace: App\\Routes\\$classname\n";