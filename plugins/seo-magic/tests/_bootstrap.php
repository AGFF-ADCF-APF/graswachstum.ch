<?php
// Load Composer autoloader and Grav core (project root)
$projectAutoload = getcwd() . '/vendor/autoload.php';
if (file_exists($projectAutoload)) {
    require_once $projectAutoload;
} else {
    // Fallback if run from elsewhere: try relative to this file
    $fallback = __DIR__ . '/../../../vendor/autoload.php';
    if (file_exists($fallback)) {
        require_once $fallback;
    }
}

use Grav\Common\Grav;

// Register a PSR-4 autoloader for the SEO Magic plugin classes.
spl_autoload_register(function ($class) {
    $prefix = 'Grav\\Plugin\\SEOMagic\\';
    $baseDir = __DIR__ . '/../classes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Seed a minimal Grav container with stubbed services used by the plugin.
$grav = Grav::instance();

// Minimal config stub: return defaults when asked.
$grav['config'] = new class {
    public function get($key, $default = null) { return $default; }
};

// Minimal language stub used by SEOScore and SEOMagic.
$grav['language'] = new class {
    public function translate($key) { return $key; }
    public function getLanguage() { return 'en'; }
};
