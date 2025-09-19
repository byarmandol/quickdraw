<?php
// Quick Draw - Google Vision API Handler
$apiKey = getenv('GOOGLE_VISION_API_KEY');

// Buscar API key en archivo .env si no está en variable de entorno
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

// Fallback a constante hardcodeada
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

// Control de límite de peticiones
session_start();
$now = time();
$requests = 'vision_requests';
$timeWindow = 'vision_time_window';

if (!isset($_SESSION[$requests])) {
    $_SESSION[$requests] = 0;
    $_SESSION[$timeWindow] = $now;
}

// Resetear contador cada 5 minutos
if (($now - $_SESSION[$timeWindow]) > 300) {
    $_SESSION[$requests] = 0;
    $_SESSION[$timeWindow] = $now;
}

if ($_SESSION[$requests] >= 25) { // Incrementé el límite
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
    
    // Limpiar data URL si viene con prefijo
    if (strpos($imageData, 'data:image') === 0) {
        $parts = explode(',', $imageData);
        if (count($parts) === 2) {
            $imageData = $parts[1];
        }
    }
    
    // Validar base64
    if (!base64_decode($imageData, true)) {
        throw new Exception('Imagen base64 inválida');
    }
    
    // Límite de tamaño
    if (strlen($imageData) > 4 * 1024 * 1024 * 4/3) { // ~4MB
        throw new Exception('Imagen demasiado grande');
    }
    
    $apiUrl = 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($apiKey);
    
    // Configuración de la petición a Google Vision
    $requestBody = [
        'requests' => [
            [
                'image' => ['content' => $imageData],
                'features' => [
                    ['type' => 'LABEL_DETECTION', 'maxResults' => 15],
                    ['type' => 'OBJECT_LOCALIZATION', 'maxResults' => 15],
                    ['type' => 'TEXT_DETECTION', 'maxResults' => 8],
                    ['type' => 'WEB_DETECTION', 'maxResults' => 10]
                ],
                'imageContext' => [
                    'languageHints' => ['en', 'es']
                ]
            ]
        ]
    ];
    
    // Ejecutar petición
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15
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
    $seenLabels = []; // Evitar duplicados
    
    // Procesar etiquetas (labels)
    if (isset($visionResponse['labelAnnotations'])) {
        foreach ($visionResponse['labelAnnotations'] as $label) {
            $originalLabel = $label['description'];
            $translatedLabel = translateToSpanish($originalLabel);
            $confidence = round($label['score'] * 100, 1);
            
            $labelKey = strtolower($translatedLabel);
            if (!isset($seenLabels[$labelKey]) && $confidence >= 40) {
                $predictions[] = [
                    'label' => $translatedLabel,
                    'confidence' => $confidence,
                    'type' => 'label',
                    'original' => $originalLabel
                ];
                $seenLabels[$labelKey] = true;
            }
        }
    }
    
    // Procesar objetos localizados
    if (isset($visionResponse['localizedObjectAnnotations'])) {
        foreach ($visionResponse['localizedObjectAnnotations'] as $object) {
            $originalLabel = $object['name'];
            $translatedLabel = translateToSpanish($originalLabel);
            $confidence = round($object['score'] * 100, 1);
            
            $labelKey = strtolower($translatedLabel);
            if (!isset($seenLabels[$labelKey]) && $confidence >= 35) {
                $predictions[] = [
                    'label' => $translatedLabel,
                    'confidence' => $confidence,
                    'type' => 'object',
                    'original' => $originalLabel
                ];
                $seenLabels[$labelKey] = true;
            }
        }
    }
    
    // Procesar detección web para obtener más entidades
    if (isset($visionResponse['webDetection']['webEntities'])) {
        foreach ($visionResponse['webDetection']['webEntities'] as $entity) {
            if (isset($entity['description']) && $entity['score'] >= 0.3) {
                $originalLabel = $entity['description'];
                $translatedLabel = translateToSpanish($originalLabel);
                $confidence = round($entity['score'] * 100, 1);
                
                $labelKey = strtolower($translatedLabel);
                if (!isset($seenLabels[$labelKey]) && strlen($originalLabel) <= 25) {
                    $predictions[] = [
                        'label' => $translatedLabel,
                        'confidence' => min($confidence, 85),
                        'type' => 'web',
                        'original' => $originalLabel
                    ];
                    $seenLabels[$labelKey] = true;
                }
            }
        }
    }
    
    // Procesar texto detectado
    if (isset($visionResponse['textAnnotations'])) {
        foreach ($visionResponse['textAnnotations'] as $index => $text) {
            if ($index === 0) continue; // Saltar texto completo
            $textContent = trim($text['description']);
            if (strlen($textContent) <= 20 && strlen($textContent) >= 2) {
                $labelKey = strtolower($textContent);
                if (!isset($seenLabels[$labelKey])) {
                    $predictions[] = [
                        'label' => $textContent,
                        'confidence' => 75,
                        'type' => 'text',
                        'original' => $textContent
                    ];
                    $seenLabels[$labelKey] = true;
                }
            }
        }
    }
    
    // Ordenar por relevancia (objetos y labels primero, luego por confianza)
    usort($predictions, function($a, $b) {
        $typeOrder = ['object' => 4, 'label' => 3, 'web' => 2, 'text' => 1];
        $aWeight = $typeOrder[$a['type']] ?? 1;
        $bWeight = $typeOrder[$b['type']] ?? 1;
        
        if ($aWeight !== $bWeight) {
            return $bWeight - $aWeight;
        }
        
        return $b['confidence'] <=> $a['confidence'];
    });
    
    // Limitar resultados
    $predictions = array_slice($predictions, 0, 10);
    
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

// Función de traducción mejorada
function translateToSpanish($term) {
    // Diccionario de traducciones expandido
    $dictionary = [
        // Animales
        'animal' => 'animal',
        'cat' => 'gato', 'feline' => 'gato', 'kitty' => 'gato', 'kitten' => 'gato',
        'domestic cat' => 'gato', 'house cat' => 'gato', 'pet cat' => 'gato',
        'dog' => 'perro', 'canine' => 'perro', 'puppy' => 'perro', 'hound' => 'perro',
        'domestic dog' => 'perro', 'pet dog' => 'perro', 'mutt' => 'perro',
        'bird' => 'pájaro', 'avian' => 'pájaro', 'fowl' => 'pájaro', 'flying bird' => 'pájaro',
        'songbird' => 'pájaro', 'feathered' => 'pájaro',
        'fish' => 'pez', 'aquatic' => 'pez', 'marine life' => 'pez', 'seafood' => 'pez',
        'horse' => 'caballo', 'equine' => 'caballo', 'stallion' => 'caballo', 'mare' => 'caballo',
        'cow' => 'vaca', 'cattle' => 'vaca', 'bovine' => 'vaca', 'bull' => 'vaca',
        'pig' => 'cerdo', 'swine' => 'cerdo', 'hog' => 'cerdo', 'boar' => 'cerdo',
        'sheep' => 'oveja', 'lamb' => 'oveja', 'wool' => 'oveja',
        'rabbit' => 'conejo', 'bunny' => 'conejo', 'hare' => 'conejo',
        'mouse' => 'ratón', 'rodent' => 'ratón', 'mice' => 'ratón',
        'butterfly' => 'mariposa', 'moth' => 'mariposa', 'flying insect' => 'mariposa',
        'bee' => 'abeja', 'honeybee' => 'abeja', 'bumblebee' => 'abeja',
        'spider' => 'araña', 'arachnid' => 'araña',
        'snake' => 'serpiente', 'serpent' => 'serpiente',
        'turtle' => 'tortuga', 'tortoise' => 'tortuga',
        'elephant' => 'elefante', 'trunk' => 'elefante',
        'lion' => 'león', 'big cat' => 'león', 'mane' => 'león',
        'tiger' => 'tigre', 'striped' => 'tigre',
        'bear' => 'oso', 'bruin' => 'oso',
        
        // Objetos de casa
        'house' => 'casa', 'home' => 'casa', 'residence' => 'casa', 'dwelling' => 'casa',
        'building' => 'casa', 'residential' => 'casa', 'shelter' => 'casa',
        'door' => 'puerta', 'entrance' => 'puerta', 'entry' => 'puerta', 'doorway' => 'puerta',
        'window' => 'ventana', 'glass' => 'ventana', 'pane' => 'ventana',
        'table' => 'mesa', 'desk' => 'mesa', 'surface' => 'mesa', 'dining table' => 'mesa',
        'chair' => 'silla', 'seat' => 'silla', 'sitting' => 'silla',
        'bed' => 'cama', 'mattress' => 'cama', 'sleep' => 'cama',
        'sofa' => 'sofá', 'couch' => 'sofá',
        'lamp' => 'lámpara', 'light' => 'lámpara', 'lighting' => 'lámpara',
        'furniture' => 'mueble',
        
        // Tecnología
        'book' => 'libro', 'reading' => 'libro', 'literature' => 'libro', 'pages' => 'libro',
        'phone' => 'teléfono', 'telephone' => 'teléfono', 'mobile' => 'teléfono',
        'smartphone' => 'teléfono', 'cell phone' => 'teléfono',
        'computer' => 'computadora', 'pc' => 'computadora', 'laptop' => 'computadora',
        'television' => 'televisión', 'tv' => 'televisión', 'screen' => 'televisión',
        'monitor' => 'televisión',
        'clock' => 'reloj', 'watch' => 'reloj', 'time' => 'reloj', 'timepiece' => 'reloj',
        'mirror' => 'espejo', 'reflection' => 'espejo',
        'picture' => 'cuadro', 'painting' => 'pintura', 'artwork' => 'arte', 'art' => 'arte',
        
        // Vehículos
        'car' => 'coche', 'vehicle' => 'coche', 'automobile' => 'coche', 'auto' => 'coche',
        'motor vehicle' => 'coche', 'sedan' => 'coche',
        'truck' => 'camión', 'lorry' => 'camión',
        'bus' => 'autobús', 'coach' => 'autobús',
        'train' => 'tren', 'locomotive' => 'tren', 'railway' => 'tren',
        'plane' => 'avión', 'airplane' => 'avión', 'aircraft' => 'avión', 'jet' => 'avión',
        'flying' => 'avión', 'aviation' => 'avión',
        'boat' => 'barco', 'ship' => 'barco', 'vessel' => 'barco',
        'bicycle' => 'bicicleta', 'bike' => 'bicicleta', 'cycling' => 'bicicleta',
        'motorcycle' => 'motocicleta', 'motorbike' => 'motocicleta',
        'wheel' => 'rueda', 'tire' => 'rueda',
        
        // Naturaleza
        'tree' => 'árbol', 'plant' => 'árbol', 'vegetation' => 'árbol', 'trunk' => 'árbol',
        'branches' => 'árbol', 'foliage' => 'árbol',
        'flower' => 'flor', 'bloom' => 'flor', 'blossom' => 'flor', 'petal' => 'flor',
        'rose' => 'flor', 'tulip' => 'flor', 'daisy' => 'flor',
        'grass' => 'césped', 'lawn' => 'césped', 'green' => 'césped',
        'leaf' => 'hoja', 'leaves' => 'hoja',
        'sun' => 'sol', 'solar' => 'sol', 'sunshine' => 'sol', 'bright' => 'sol',
        'star' => 'sol', 'daylight' => 'sol',
        'moon' => 'luna', 'lunar' => 'luna', 'night' => 'luna', 'crescent' => 'luna',
        'celestial' => 'estrella', 'night sky' => 'estrella', 'stellar' => 'estrella',
        'twinkle' => 'estrella',
        'cloud' => 'nube', 'sky' => 'cielo', 'weather' => 'nube', 'fluffy' => 'nube',
        'water' => 'agua', 'liquid' => 'agua', 'wet' => 'agua',
        'fire' => 'fuego', 'flame' => 'fuego', 'burning' => 'fuego', 'hot' => 'fuego',
        'mountain' => 'montaña', 'peak' => 'montaña', 'hill' => 'montaña',
        'river' => 'río', 'stream' => 'río', 'flowing' => 'río',
        'sea' => 'mar', 'ocean' => 'mar', 'waves' => 'mar', 'marine' => 'mar',
        'beach' => 'playa', 'shore' => 'playa', 'sand' => 'playa', 'coast' => 'playa',
        'forest' => 'bosque', 'woods' => 'bosque', 'trees' => 'bosque',
        
        // Cuerpo humano
        'person' => 'persona', 'human' => 'persona', 'people' => 'persona',
        'man' => 'persona', 'woman' => 'persona',
        'face' => 'cara', 'head' => 'cabeza', 'facial' => 'cara',
        'hair' => 'cabello', 'skull' => 'cabeza',
        'eye' => 'ojo', 'vision' => 'ojo', 'sight' => 'ojo',
        'nose' => 'nariz', 'smell' => 'nariz',
        'mouth' => 'boca', 'lips' => 'boca',
        'ear' => 'oreja', 'hearing' => 'oreja',
        'hand' => 'mano', 'palm' => 'mano', 'fingers' => 'mano', 'finger' => 'dedo',
        'arm' => 'brazo', 'leg' => 'pierna', 'foot' => 'pie', 'feet' => 'pie',
        
        // Comida
        'food' => 'comida', 'meal' => 'comida', 'eating' => 'comida',
        'apple' => 'manzana', 'fruit' => 'manzana',
        'banana' => 'plátano', 'yellow fruit' => 'plátano',
        'orange' => 'naranja', 'citrus' => 'naranja',
        'bread' => 'pan', 'loaf' => 'pan', 'baked' => 'pan',
        'cake' => 'pastel', 'dessert' => 'pastel', 'sweet' => 'pastel',
        'pizza' => 'pizza', 'cheese' => 'pizza',
        'hamburger' => 'hamburguesa', 'burger' => 'hamburguesa',
        'sandwich' => 'sándwich',
        'ice cream' => 'helado', 'frozen' => 'helado', 'cold' => 'helado',
        'coffee' => 'café', 'drink' => 'café', 'beverage' => 'café',
        'tea' => 'té',
        'milk' => 'leche', 'dairy' => 'leche',
        'egg' => 'huevo', 'oval' => 'huevo',
        
        // Formas
        'drawing' => 'dibujo', 'sketch' => 'dibujo', 'illustration' => 'dibujo',
        'line' => 'línea', 'stroke' => 'línea',
        'circle' => 'círculo', 'round' => 'círculo', 'circular' => 'círculo',
        'ring' => 'círculo', 'oval' => 'círculo',
        'square' => 'cuadrado', 'rectangle' => 'cuadrado', 'box' => 'cuadrado',
        'triangle' => 'triángulo', 'triangular' => 'triángulo',
        'shape' => 'forma', 'form' => 'forma',
        
        // Colores
        'black' => 'negro', 'dark' => 'negro',
        'white' => 'blanco', 'light' => 'blanco',
        'red' => 'rojo', 'blue' => 'azul', 'green' => 'verde', 'yellow' => 'amarillo',
        
        // Corazón y emociones
        'heart' => 'corazón', 'love' => 'corazón', 'romantic' => 'corazón',
        
        // Términos adicionales comunes
        'insect' => 'insecto', 'mammal' => 'animal', 'reptile' => 'animal',
        'vertebrate' => 'animal', 'organism' => 'animal',
        'outdoor' => 'exterior', 'indoor' => 'interior',
        'handwriting' => 'escritura', 'writing' => 'escritura', 'text' => 'texto',
        'number' => 'número', 'symbol' => 'símbolo'
    ];
    
    $lowerTerm = strtolower(trim($term));
    
    // Búsqueda exacta
    if (isset($dictionary[$lowerTerm])) {
        return $dictionary[$lowerTerm];
    }
    
    // Búsqueda por inclusión (más flexible)
    foreach ($dictionary as $english => $spanish) {
        if (strpos($lowerTerm, $english) !== false) {
            return $spanish;
        }
        // También buscar al revés para términos compuestos
        if (strlen($lowerTerm) > 3 && strpos($english, $lowerTerm) !== false) {
            return $spanish;
        }
    }
    
    // Si no encuentra traducción, devolver término original capitalizado
    return ucfirst(trim($term));
}
?>
