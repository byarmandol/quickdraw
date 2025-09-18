<?php
// Quick Draw - Google Vision API Handler
$apiKey = getenv('GOOGLE_VISION_API_KEY');

if (!$apiKey && file_exists(__DIR__ . '/.env')) {
    $envFile = file_get_contents(__DIR__ . '/.env');
    $lines = explode("\n", $envFile);
    foreach ($lines as $line) {
        if (strpos($line, 'GOOGLE_VISION_API_KEY=') === 0) {
            $apiKey = trim(substr($line, strlen('GOOGLE_VISION_API_KEY=')));
            break;
        }
    }
}

if (!$apiKey) {
    define('GOOGLE_CLOUD_API_KEY', 'TU_API_KEY_AQUI');
    $apiKey = GOOGLE_CLOUD_API_KEY;
    error_log('WARNING: Using hardcoded API key');
}

if (!$apiKey || $apiKey === 'TU_API_KEY_AQUI') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Clave de API no configurada']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Solo POST permitido']);
    exit();
}

// Basic rate limiting
session_start();
$now = time();
$requests = 'vision_requests';
$timeWindow = 'vision_time_window';

if (!isset($_SESSION[$requests])) {
    $_SESSION[$requests] = 0;
    $_SESSION[$timeWindow] = $now;
}

if (($now - $_SESSION[$timeWindow]) > 300) {
    $_SESSION[$requests] = 0;
    $_SESSION[$timeWindow] = $now;
}

if ($_SESSION[$requests] >= 20) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Límite de peticiones excedido']);
    exit();
}

$_SESSION[$requests]++;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['image'])) {
        throw new Exception('Faltan datos de imagen');
    }
    
    $imageData = $input['image'];
    if (strpos($imageData, 'data:image') === 0) {
        $parts = explode(',', $imageData);
        if (count($parts) === 2) {
            $imageData = $parts[1];
        }
    }
    
    if (!base64_decode($imageData, true)) {
        throw new Exception('Imagen base64 inválida');
    }
    
    if (strlen($imageData) > 4 * 1024 * 1024 * 4/3) {
        throw new Exception('Imagen demasiado grande');
    }
    
    $apiUrl = 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($apiKey);
    
    $requestBody = [
        'requests' => [
            [
                'image' => ['content' => $imageData],
                'features' => [
                    ['type' => 'LABEL_DETECTION', 'maxResults' => 10],
                    ['type' => 'OBJECT_LOCALIZATION', 'maxResults' => 10],
                    ['type' => 'TEXT_DETECTION', 'maxResults' => 5]
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('Error de conexión: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        $errorMsg = 'HTTP ' . $httpCode;
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= ': ' . $errorData['error']['message'];
            }
        }
        throw new Exception($errorMsg);
    }
    
    $result = json_decode($response, true);
    if (!$result || !isset($result['responses'][0])) {
        throw new Exception('Respuesta de API inválida');
    }
    
    $visionResponse = $result['responses'][0];
    $predictions = [];
    
    // Procesar etiquetas
    if (isset($visionResponse['labelAnnotations'])) {
        foreach ($visionResponse['labelAnnotations'] as $label) {
            $spanishLabel = translateToSpanish($label['description']);
            $predictions[] = [
                'label' => $spanishLabel,
                'confidence' => round($label['score'] * 100, 1),
                'type' => 'label',
                'original' => $label['description']
            ];
        }
    }
    
    // Procesar objetos
    if (isset($visionResponse['localizedObjectAnnotations'])) {
        foreach ($visionResponse['localizedObjectAnnotations'] as $object) {
            $spanishLabel = translateToSpanish($object['name']);
            $predictions[] = [
                'label' => $spanishLabel,
                'confidence' => round($object['score'] * 100, 1),
                'type' => 'object',
                'original' => $object['name']
            ];
        }
    }
    
    // Procesar texto
    if (isset($visionResponse['textAnnotations'])) {
        foreach ($visionResponse['textAnnotations'] as $index => $text) {
            if ($index === 0) continue;
            $textContent = trim($text['description']);
            if (strlen($textContent) <= 15 && strlen($textContent) >= 2) {
                $predictions[] = [
                    'label' => $textContent,
                    'confidence' => 85,
                    'type' => 'text',
                    'original' => $textContent
                ];
            }
        }
    }
    
    usort($predictions, function($a, $b) {
        return $b['confidence'] <=> $a['confidence'];
    });
    
    $predictions = array_slice($predictions, 0, 8);
    
    echo json_encode([
        'success' => true,
        'predictions' => $predictions,
        'timestamp' => date('Y-m-d H:i:s'),
        'total_predictions' => count($predictions)
    ]);

} catch (Exception $e) {
    error_log('Vision API Error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

function translateToSpanish($term) {
    $translations = [
        'animal' => 'animal', 'cat' => 'gato', 'dog' => 'perro', 'bird' => 'pájaro',
        'fish' => 'pez', 'horse' => 'caballo', 'cow' => 'vaca', 'pig' => 'cerdo',
        'sheep' => 'oveja', 'rabbit' => 'conejo', 'mouse' => 'ratón',
        'butterfly' => 'mariposa', 'bee' => 'abeja', 'spider' => 'araña',
        'snake' => 'serpiente', 'turtle' => 'tortuga', 'elephant' => 'elefante',
        'lion' => 'león', 'tiger' => 'tigre', 'bear' => 'oso',
        'house' => 'casa', 'home' => 'casa', 'building' => 'edificio',
        'door' => 'puerta', 'window' => 'ventana', 'table' => 'mesa',
        'chair' => 'silla', 'bed' => 'cama', 'sofa' => 'sofá', 'lamp' => 'lámpara',
        'book' => 'libro', 'phone' => 'teléfono', 'computer' => 'computadora',
        'television' => 'televisión', 'tv' => 'televisión', 'clock' => 'reloj',
        'mirror' => 'espejo', 'picture' => 'cuadro', 'painting' => 'pintura',
        'car' => 'coche', 'vehicle' => 'vehículo', 'truck' => 'camión',
        'bus' => 'autobús', 'train' => 'tren', 'plane' => 'avión',
        'airplane' => 'avión', 'boat' => 'barco', 'ship' => 'barco',
        'bicycle' => 'bicicleta', 'bike' => 'bicicleta', 'motorcycle' => 'motocicleta',
        'wheel' => 'rueda', 'tree' => 'árbol', 'flower' => 'flor', 'plant' => 'planta',
        'grass' => 'césped', 'leaf' => 'hoja', 'sun' => 'sol', 'moon' => 'luna',
        'star' => 'estrella', 'cloud' => 'nube', 'sky' => 'cielo', 'water' => 'agua',
        'fire' => 'fuego', 'mountain' => 'montaña', 'river' => 'río', 'sea' => 'mar',
        'ocean' => 'océano', 'beach' => 'playa', 'forest' => 'bosque',
        'person' => 'persona', 'human' => 'persona', 'face' => 'cara',
        'head' => 'cabeza', 'hair' => 'cabello', 'eye' => 'ojo', 'nose' => 'nariz',
        'mouth' => 'boca', 'ear' => 'oreja', 'hand' => 'mano', 'finger' => 'dedo',
        'arm' => 'brazo', 'leg' => 'pierna', 'foot' => 'pie', 'food' => 'comida',
        'apple' => 'manzana', 'banana' => 'plátano', 'orange' => 'naranja',
        'bread' => 'pan', 'cake' => 'pastel', 'pizza' => 'pizza',
        'hamburger' => 'hamburguesa', 'sandwich' => 'sándwich', 'ice cream' => 'helado',
        'coffee' => 'café', 'tea' => 'té', 'milk' => 'leche', 'cheese' => 'queso',
        'egg' => 'huevo', 'drawing' => 'dibujo', 'sketch' => 'boceto', 'art' => 'arte',
        'line' => 'línea', 'circle' => 'círculo', 'square' => 'cuadrado',
        'triangle' => 'triángulo', 'rectangle' => 'rectángulo', 'shape' => 'forma',
        'color' => 'color', 'black' => 'negro', 'white' => 'blanco', 'red' => 'rojo',
        'blue' => 'azul', 'green' => 'verde', 'yellow' => 'amarillo'
    ];
    
    $lower = strtolower(trim($term));
    
    if (isset($translations[$lower])) {
        return $translations[$lower];
    }
    
    foreach ($translations as $english => $spanish) {
        if (strpos($lower, $english) !== false) {
            return $spanish;
        }
    }
    
    return $term;
}
?>
