<?php
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\DynamoDb\DynamoDbClient;

// Configuración inicial de AWS
$region = 'us-east-1';
$bucketName = 'amazon-s3-proyecto'; // Verifica que coincida exactamente con tu bucket en S3
$tableName = 'publicaciones';        // Verifica que coincida con tu tabla en DynamoDB

// Inicializar clientes usando IAM LabRole (autenticación automática en EC2)
$s3 = new S3Client([
    'version' => 'latest',
    'region'  => $region
]);

$dynamoDb = new DynamoDbClient([
    'version' => 'latest',
    'region'  => $region
]);

$mensaje = "";

// 1. CREATE: Crear nueva publicación (Imagen a S3 + Registro a DynamoDB)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear') {
    $titulo = trim($_POST['titulo'] ?? '');
    $archivo = $_FILES['imagen'] ?? null;

    if (!empty($titulo) && $archivo && $archivo['error'] === UPLOAD_ERR_OK) {
        try {
            $nombreArchivo = time() . '_' . basename($archivo['name']);
            $s3Key = 'imagenes/' . $nombreArchivo;

            // Subir archivo a Amazon S3
            $resultS3 = $s3->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $s3Key,
                'SourceFile'  => $archivo['tmp_name'],
                'ContentType' => $archivo['type']
            ]);

            $urlImagen = $resultS3['ObjectURL'];

            // Insertar registro en DynamoDB
            $dynamoDb->putItem([
                'TableName' => $tableName,
                'Item' => [
                    'id'         => ['S' => uniqid('img_')],
                    'titulo'     => ['S' => $titulo],
                    'imagen_url' => ['S' => $urlImagen],
                    's3_key'     => ['S' => $s3Key],
                    'fecha'      => ['S' => date('Y-m-d H:i:s')]
                ]
            ]);

            $mensaje = "¡Publicación creada exitosamente en AWS (Create)!";
        } catch (Exception $e) {
            $mensaje = "Error al crear: " . $e->getMessage();
        }
    } else {
        $mensaje = "Completa el título y sube una imagen válida.";
    }
}

// 2. UPDATE: Actualizar título de la publicación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar') {
    $id = $_POST['id'] ?? '';
    $nuevoTitulo = trim($_POST['nuevo_titulo'] ?? '');

    if (!empty($id) && !empty($nuevoTitulo)) {
        try {
            $dynamoDb->updateItem([
                'TableName' => $tableName,
                'Key' => [
                    'id' => ['S' => $id]
                ],
                'UpdateExpression' => 'SET titulo = :t',
                'ExpressionAttributeValues' => [
                    ':t' => ['S' => $nuevoTitulo]
                ]
            ]);
            $mensaje = "¡Publicación actualizada exitosamente (Update)!";
        } catch (Exception $e) {
            $mensaje = "Error al actualizar: " . $e->getMessage();
        }
    }
}

// 3. DELETE: Eliminar registro en DynamoDB y su archivo en S3
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
    $id = $_POST['id'] ?? '';
    $s3Key = $_POST['s3_key'] ?? '';

    if (!empty($id)) {
        try {
            // Eliminar de DynamoDB
            $dynamoDb->deleteItem([
                'TableName' => $tableName,
                'Key' => [
                    'id' => ['S' => $id]
                ]
            ]);

            // Eliminar objeto de Amazon S3 si la clave existe
            if (!empty($s3Key)) {
                $s3->deleteObject([
                    'Bucket' => $bucketName,
                    'Key'    => $s3Key
                ]);
            }

            $mensaje = "¡Publicación eliminada correctamente (Delete)!";
        } catch (Exception $e) {
            $mensaje = "Error al eliminar: " . $e->getMessage();
        }
    }
}

// 4. READ: Leer todas las publicaciones desde DynamoDB
$registros = [];
try {
    $scanResult = $dynamoDb->scan(['TableName' => $tableName]);
    $registros = $scanResult['Items'] ?? [];
} catch (Exception $e) {
    // Si la tabla está vacía o hay error inicial
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplicación Web AWS - CRUD S3 & DynamoDB</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; background-color: #f4f6f8; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 600px; margin-bottom: 25px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="file"] { width: 100%; padding: 8px; box-sizing: border-box; }
        button { background: #ff9900; color: #fff; border: none; padding: 10px 15px; cursor: pointer; border-radius: 4px; font-weight: bold; }
        button:hover { background: #e68a00; }
        .btn-delete { background: #d9534f; padding: 6px 10px; font-size: 12px; margin-top: 5px; }
        .btn-delete:hover { background: #c9302c; }
        .btn-edit { background: #0275d8; padding: 6px 10px; font-size: 12px; margin-top: 5px; }
        .btn-edit:hover { background: #025aa5; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; }
        .item-card { background: #fff; padding: 12px; border-radius: 6px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .item-card img { max-width: 100%; height: 140px; object-fit: cover; border-radius: 4px; }
        .msg { padding: 10px; background: #e3f2fd; color: #0d47a1; margin-bottom: 15px; border-radius: 4px; font-weight: bold; }
        .edit-form { margin-top: 10px; display: flex; gap: 5px; }
        .edit-form input { padding: 4px; font-size: 12px; }
    </style>
</head>
<body>

    <h2>Panel de Publicaciones AWS (CRUD Completo: EC2 + S3 + DynamoDB)</h2>

    <?php if ($mensaje): ?>
        <div class="msg"><?php echo htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>

    <!-- Formulario CREATE -->
    <div class="card">
        <h3>Nueva Publicación (Create)</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="crear">
            <div class="form-group">
                <label for="titulo">Título de la imagen:</label>
                <input type="text" id="titulo" name="titulo" required>
            </div>
            <div class="form-group">
                <label for="imagen">Seleccionar archivo (S3):</label>
                <input type="file" id="imagen" name="imagen" accept="image/*" required>
            </div>
            <button type="submit">Guardar en AWS</button>
        </form>
    </div>

    <!-- Sección READ, UPDATE y DELETE -->
    <h3>Publicaciones Guardadas (Read, Update, Delete)</h3>
    <div class="grid">
        <?php foreach ($registros as $item): ?>
            <?php 
                $idItem = htmlspecialchars($item['id']['S'] ?? '');
                $tituloItem = htmlspecialchars($item['titulo']['S'] ?? '');
                $urlItem = htmlspecialchars($item['imagen_url']['S'] ?? '');
                $keyItem = htmlspecialchars($item['s3_key']['S'] ?? '');
                $fechaItem = htmlspecialchars($item['fecha']['S'] ?? '');
            ?>
            <div class="item-card">
                <img src="<?php echo $urlItem; ?>" alt="Imagen">
                <h4><?php echo $tituloItem; ?></h4>
                <small><?php echo $fechaItem; ?></small>

                <!-- Formulario UPDATE -->
                <form method="POST" class="edit-form">
                    <input type="hidden" name="accion" value="actualizar">
                    <input type="hidden" name="id" value="<?php echo $idItem; ?>">
                    <input type="text" name="nuevo_titulo" placeholder="Nuevo título" required>
                    <button type="submit" class="btn-edit">Editar</button>
                </form>

                <!-- Formulario DELETE -->
                <form method="POST" onsubmit="return confirm('¿Seguro que deseas eliminar esta publicación?');">
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id" value="<?php echo $idItem; ?>">
                    <input type="hidden" name="s3_key" value="<?php echo $keyItem; ?>">
                    <button type="submit" class="btn-delete">Eliminar</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

</body>
</html>