<?php
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\DynamoDb\DynamoDbClient;

// Configuración inicial de AWS
$region     = 'us-east-1';
$bucketName = 'amazon-s3-proyecto'; // Reemplaza por el nombre exacto de tu Bucket S3
$tableName  = 'publicaciones';         // Nombre de tu tabla en DynamoDB

// Inicializar clientes usando IAM LabRole (autenticación automática en EC2)
$s3 = new S3Client([
    'version' => 'latest',
    'region'  => $region
]);

$dynamoDb = new DynamoDbClient([
    'version' => 'latest',
    'region'  => $region
]);

$mensaje = '';

// Procesar el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo  = trim($_POST['titulo'] ?? '');
    $archivo = $_FILES['imagen'] ?? null;

    if (!empty($titulo) && $archivo && $archivo['error'] === UPLOAD_ERR_OK) {
        try {
            $nombreArchivo = time() . '_' . basename($archivo['name']);

            // 1. Subir imagen a Amazon S3
            $resultS3 = $s3->putObject([
                'Bucket'      => $bucketName,
                'Key'         => 'imagenes/' . $nombreArchivo,
                'SourceFile'  => $archivo['tmp_name'],
                'ContentType' => $archivo['type']
            ]);

            $urlImagen = $resultS3['ObjectURL'];

            // 2. Insertar registro en DynamoDB
            $dynamoDb->putItem([
                'TableName' => $tableName,
                'Item' => [
                    'id'         => ['S' => uniqid('img_')],
                    'titulo'     => ['S' => $titulo],
                    'imagen_url' => ['S' => $urlImagen],
                    'fecha'      => ['S' => date('Y-m-d H:i:s')]
                ]
            ]);

            $mensaje = "¡Registro creado exitosamente en AWS!";
        } catch (Exception $e) {
            $mensaje = "Error al procesar la solicitud: " . $e->getMessage();
        }
    } else {
        $mensaje = "Por favor completa el título y selecciona una imagen válida.";
    }
}

// Obtener registros guardados en DynamoDB
$registros = [];
try {
    $scanResult = $dynamoDb->scan(['TableName' => $tableName]);
    $registros  = $scanResult['Items'] ?? [];
} catch (Exception $e) {
    // Si falla la consulta inicial, la página se renderiza vacía
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplicación Web AWS - S3 & DynamoDB</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; background-color: #f4f6f8; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 600px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="file"] { width: 100%; padding: 8px; box-sizing: border-box; }
        button { background: #ff9900; color: #fff; border: none; padding: 10px 15px; cursor: pointer; border-radius: 4px; font-weight: bold; }
        button:hover { background: #e68a00; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .item-card { background: #fff; padding: 10px; border-radius: 6px; text-align: center; }
        .item-card img { max-width: 100%; height: 150px; object-fit: cover; border-radius: 4px; }
        .msg { padding: 10px; background: #e3f2fd; color: #0d47a1; margin-bottom: 15px; border-radius: 4px; }
    </style>
</head>
<body>

    <h2>Subir Publicación a AWS (S3 + DynamoDB)</h2>

    <?php if ($mensaje): ?>
        <div class="msg"><?php echo htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="titulo">Título de la imagen:</label>
                <input type="text" id="titulo" name="titulo" required>
            </div>
            <div class="form-group">
                <label for="imagen">Seleccionar archivo:</label>
                <input type="file" id="imagen" name="imagen" accept="image/*" required>
            </div>
            <button type="submit">Guardar en AWS</button>
        </form>
    </div>

    <h3>Publicaciones Guardadas</h3>
    <div class="grid">
        <?php foreach ($registros as $item): ?>
            <div class="item-card">
                <img src="<?php echo htmlspecialchars($item['imagen_url']['S']); ?>" alt="Imagen">
                <h4><?php echo htmlspecialchars($item['titulo']['S']); ?></h4>
                <small><?php echo htmlspecialchars($item['fecha']['S']); ?></small>
            </div>
        <?php endforeach; ?>
    </div>

</body>
</html>