<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 Diagnóstico de Cron Email</h2>";

// 1. Verificar archivos con más detalle
echo "<h3>1. Verificando archivos requeridos:</h3>";
$archivos = [
    'config.php' => __DIR__ . '/config.php',
    'db.php' => __DIR__ . '/app/db.php',
    'emailer.php' => __DIR__ . '/app/utils/emailer.php'
];

foreach ($archivos as $nombre => $ruta) {
    if (file_exists($ruta)) {
        $permisos = substr(sprintf('%o', fileperms($ruta)), -4);
        $tamano = filesize($ruta);
        echo "✅ {$nombre} existe - Permisos: {$permisos} - Tamaño: {$tamano} bytes<br>";
        echo "&nbsp;&nbsp;&nbsp;Ruta: {$ruta}<br>";
    } else {
        echo "❌ {$nombre} NO EXISTE en {$ruta}<br>";
    }
}

echo "<h3>2. Mostrando contenido de db.php:</h3>";
$db_path = __DIR__ . '/app/db.php';
if (file_exists($db_path)) {
    echo "<pre style='background: #f4f4f4; padding: 10px;'>";
    echo htmlspecialchars(file_get_contents($db_path));
    echo "</pre>";
}

// 3. Probar require con manejo de errores
echo "<h3>3. Intentando cargar archivos:</h3>";
try {
    require_once __DIR__ . '/config.php';
    echo "✅ config.php cargado<br>";
} catch (Throwable $e) {
    echo "❌ Error config.php: " . $e->getMessage() . "<br>";
}

try {
    // Usar ruta absoluta completa
    $db_file = __DIR__ . '/app/db.php';
    echo "Intentando cargar: {$db_file}<br>";

    if (!is_readable($db_file)) {
        echo "❌ db.php no es legible (problema de permisos)<br>";
    } else {
        require_once $db_file;
        echo "✅ db.php cargado<br>";
    }
} catch (Throwable $e) {
    echo "❌ Error db.php: " . $e->getMessage() . "<br>";
    echo "Trace: <pre>" . $e->getTraceAsString() . "</pre>";
}

try {
    require_once __DIR__ . '/app/utils/emailer.php';
    echo "✅ emailer.php cargado<br>";
} catch (Throwable $e) {
    echo "❌ Error emailer.php: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<p>Directorio actual: " . __DIR__ . "</p>";
echo "<p>Usuario PHP: " . get_current_user() . "</p>";
