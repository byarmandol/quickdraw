# Quick Draw Español

Un juego de dibujo que usa Google Vision API para reconocer lo que dibujas.

## Qué hace

Básicamente te da una palabra y tienes que dibujarla en 25 segundos. La IA de Google intenta adivinar qué dibujaste. Si acierta, ganas puntos.

## Cómo usar

1. Necesitas una clave API de Google Vision
2. Ponla en una variable de entorno `GOOGLE_VISION_API_KEY` 
3. O créate un archivo `.env` con la clave
4. Abre `index.html` en tu navegador
5. Dale a "Iniciar Juego"

## Archivos

- `index.html` - La página principal
- `game-vision.js` - Toda la lógica del juego
- `api-vision.php` - Backend que se conecta con Google Vision
- `styles.css` - Los estilos

## Configuración

Para que funcione necesitas:
- Un servidor web con PHP (puede ser local)
- Clave API de Google Cloud Vision

La clave se puede poner de varias formas:
- Variable de entorno: `export GOOGLE_VISION_API_KEY="tu-clave-aqui"`
- Archivo .env: `GOOGLE_VISION_API_KEY=tu-clave-aqui`
- Como último recurso, hardcodeada en el PHP (no recomendado)

## Limitaciones

- Tiene limitación de velocidad básica (20 peticiones cada 5 minutos)
- Solo reconoce objetos que estén en el diccionario de traducción
- A veces la IA no reconoce dibujos abstractos

## Pendientes

- Agregar más palabras
- Mejorar las traducciones
- Quizás agregar niveles de dificultad

## Problemas conocidos

Si no funciona, revisa:
- Que la clave API esté bien configurada
- Que tengas créditos en Google Cloud
- Que el servidor PHP esté corriendo

## Créditos

Usa Google Vision API para el reconocimiento de imágenes.
