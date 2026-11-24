<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================
// 1. CONEXIÓN A TIDB CLOUD / MYSQL (Variables de Entorno Render)
// ==========================================
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '4000';
$db   = getenv('DB_NAME') ?: 'toyswa';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$opciones = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Habilitar SSL obligatorio para TiDB Cloud Serverless
if (getenv('DB_HOST')) {
    $caPath = '/etc/ssl/certs/ca-certificates.crt';
    if (file_exists($caPath)) {
        $opciones[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
    }
    $opciones[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $opciones);
    $pdo->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
} catch (PDOException $e) {
    die("Error de conexión a la base de datos '$db': " . htmlspecialchars($e->getMessage()));
}

// ==========================================
// 2. CREACIÓN AUTOMÁTICA DE TABLA VENDEDORES Y CARPETA UPLOADS
// ==========================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendedores (
            id_vendedor INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nombre_completo VARCHAR(120) NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            id_sucursal INT(11) NOT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_vendedor_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursales(id_sucursal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (PDOException $e) {
    // Continuar si ya existe
}

// ==========================================
// 3. FUNCIONES AUXILIARES Y CONTROL DE ACCESO
// ==========================================
function procesarSubidaFoto(string $inputName): ?string {
    if (empty($_FILES[$inputName]['name']) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al subir la imagen (código " . $_FILES[$inputName]['error'] . ").");
    }

    if ($_FILES[$inputName]['size'] > 5 * 1024 * 1024) {
        throw new Exception("La imagen supera el tamaño máximo permitido de 5 MB.");
    }

    $permitidos = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES[$inputName]['tmp_name']);

    if (!isset($permitidos[$mime])) {
        throw new Exception("Formato de imagen no válido. Solo se permiten JPG, PNG o WEBP.");
    }

    $carpeta = 'uploads';
    if (!is_dir($carpeta) && !mkdir($carpeta, 0755, true)) {
        throw new Exception("No se pudo crear la carpeta 'uploads/' en el servidor.");
    }

    $nombreArchivo = 'juguete_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $permitidos[$mime];
    $rutaDestino   = $carpeta . '/' . $nombreArchivo;

    if (!move_uploaded_file($_FILES[$inputName]['tmp_name'], $rutaDestino)) {
        throw new Exception("No se pudo guardar la imagen en el servidor.");
    }

    return $rutaDestino;
}

function estaLogueado(): bool {
    return !empty($_SESSION['usuario_id']) && !empty($_SESSION['usuario_rol']);
}

function esAdmin(): bool {
    return ($_SESSION['usuario_rol'] ?? '') === 'admin';
}

function esVendedor(): bool {
    return ($_SESSION['usuario_rol'] ?? '') === 'vendedor';
}

function esVendedorOAdmin(): bool {
    return in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'vendedor'], true);
}

function exigirAcceso(bool $soloAdmin = false): void {
    if (!estaLogueado()) {
        header("Location: login.php");
        exit;
    }
    if ($soloAdmin && !esAdmin()) {
        header("Location: index.php?error=acceso_denegado");
        exit;
    }
}
?>
