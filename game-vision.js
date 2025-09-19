// Quick Draw Game
let canvas, ctx;
let isDrawing = false;
let gameActive = false;
let currentWord = '';
let score = 0;
let currentRound = 0;
let totalRounds = 5;
let timeLeft = 25;
let gameTimer;

const drawWords = ['casa', 'árbol', 'coche', 'gato', 'perro', 'sol', 'luna', 'estrella', 'flor', 'pez', 'pájaro', 'corazón', 'avión', 'círculo', 'cuadrado', 'triángulo', 'cara', 'mano'];

let gameState = { 
    isInitialized: false, 
    lastPrediction: 0, 
    correctGuesses: 0,
    apiActive: false
};

// Diccionario mejorado para reconocimiento
const wordMappings = {
    'casa': ['house', 'home', 'residence', 'dwelling', 'building', 'residential', 'shelter', 'abode'],
    'gato': ['cat', 'feline', 'kitty', 'kitten', 'pet cat', 'domestic cat', 'house cat'],
    'perro': ['dog', 'canine', 'puppy', 'hound', 'pet dog', 'domestic dog', 'mutt'],
    'pájaro': ['bird', 'avian', 'fowl', 'feathered', 'flying bird', 'songbird'],
    'pez': ['fish', 'aquatic', 'marine life', 'seafood', 'swimming fish'],
    'caballo': ['horse', 'equine', 'stallion', 'mare', 'pony', 'foal'],
    'vaca': ['cow', 'cattle', 'bovine', 'bull', 'calf', 'livestock'],
    'árbol': ['tree', 'plant', 'vegetation', 'forest', 'trunk', 'branches', 'foliage'],
    'flor': ['flower', 'bloom', 'blossom', 'petal', 'plant', 'rose', 'tulip', 'daisy'],
    'coche': ['car', 'vehicle', 'automobile', 'auto', 'sedan', 'motor vehicle', 'transportation'],
    'avión': ['plane', 'airplane', 'aircraft', 'jet', 'flying', 'aviation'],
    'sol': ['sun', 'solar', 'sunshine', 'bright', 'star', 'daylight'],
    'luna': ['moon', 'lunar', 'night', 'crescent', 'satellite'],
    'estrella': ['star', 'celestial', 'night sky', 'stellar', 'twinkle'],
    'círculo': ['circle', 'round', 'circular', 'ring', 'oval'],
    'cuadrado': ['square', 'rectangle', 'box', 'quadrilateral'],
    'triángulo': ['triangle', 'triangular', 'three sides'],
    'corazón': ['heart', 'love', 'romantic', 'cardiac', 'valentine'],
    'cara': ['face', 'head', 'facial', 'visage', 'countenance'],
    'mano': ['hand', 'palm', 'fingers', 'grip', 'grasp']
};

// Categorías para reconocimiento inteligente  
const categories = {
    'animal': ['gato', 'perro', 'pájaro', 'pez', 'caballo', 'vaca'],
    'transporte': ['coche', 'avión'],
    'naturaleza': ['árbol', 'flor', 'sol', 'luna', 'estrella'],
    'formas': ['círculo', 'cuadrado', 'triángulo'],
    'cuerpo': ['cara', 'mano']
};

function initGame() {
    console.log('Iniciando juego...');
    
    canvas = document.getElementById('drawCanvas');
    if (!canvas) {
        console.error('Canvas no encontrado');
        return false;
    }
    
    ctx = canvas.getContext('2d');
    if (!ctx) {
        console.error('No se pudo obtener contexto 2D');
        return false;
    }
    
    // Setup canvas
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#333';
    ctx.fillStyle = 'white';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    // Add event listeners
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);
    
    gameState.isInitialized = true;
    updateUI();
    showMessage('¡Juego listo!', 'success');
    
    return true;
}

// Inicialización múltiple para asegurar que funcione
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(initGame, 200);
    });
} else {
    setTimeout(initGame, 100);
}

window.addEventListener('load', function() {
    setTimeout(() => {
        if (!gameState.isInitialized) {
            initGame();
        }
    }, 300);
});

function startGame() {
    if (!gameState.isInitialized) {
        showMessage('Juego no está listo', 'error');
        return false;
    }
    
    gameActive = true;
    score = 0;
    currentRound = 0;
    gameState.correctGuesses = 0;
    
    updateButtons(true);
    nextRound();
    showMessage('¡Juego iniciado!', 'success');
    
    return true;
}

function nextRound() {
    if (!gameActive) return;
    
    if (currentRound >= totalRounds) {
        endGame();
        return;
    }
    
    currentRound++;
    currentWord = drawWords[Math.floor(Math.random() * drawWords.length)];
    timeLeft = 25;
    
    clearCanvas();
    updateUI();
    startTimer();
    
    console.log(`Ronda ${currentRound}: ${currentWord}`);
    showMessage(`Ronda ${currentRound}: Dibuja "${currentWord}"`, 'info');
}

function startTimer() {
    clearInterval(gameTimer);
    gameTimer = setInterval(() => {
        timeLeft--;
        updateUI();
        
        if (timeLeft <= 0) {
            clearInterval(gameTimer);
            showMessage('¡Se acabó el tiempo!', 'error');
            setTimeout(nextRound, 2000);
        }
    }, 1000);
}

function endGame() {
    gameActive = false;
    clearInterval(gameTimer);
    updateButtons(false);
    
    const accuracy = Math.round((gameState.correctGuesses / totalRounds) * 100);
    showGameEndModal(score, accuracy, gameState.correctGuesses, totalRounds);
}

function showGameEndModal(finalScore, accuracy, correct, total) {
    const modal = document.createElement('div');
    modal.id = 'gameEndModal';
    modal.innerHTML = `
        <div class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>¡Juego Completado!</h2>
                </div>
                
                <div class="modal-body">
                    <div class="score-display">
                        <div class="final-score">
                            <span class="score-label">Puntuación Final</span>
                            <span class="score-value">${finalScore}</span>
                        </div>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-icon">🎯</div>
                            <div class="stat-info">
                                <span class="stat-label">Precisión</span>
                                <span class="stat-value">${accuracy}%</span>
                            </div>
                        </div>
                        
                        <div class="stat-item">
                            <div class="stat-icon">✅</div>
                            <div class="stat-info">
                                <span class="stat-label">Correctas</span>
                                <span class="stat-value">${correct}/${total}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="performance-section">
                        <h3>Rendimiento</h3>
                        <div class="performance-bar">
                            <div class="performance-fill" style="width: ${accuracy}%"></div>
                            <span class="performance-text">${getPerformanceText(accuracy)}</span>
                        </div>
                    </div>
                    
                    <div class="motivational-message">
                        ${getMotivationalMessage(accuracy, finalScore)}
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button class="btn-modal btn-primary" onclick="closeGameEndModal(); startGame();">
                        Jugar de Nuevo
                    </button>
                    <button class="btn-modal btn-secondary" onclick="closeGameEndModal();">
                        Aceptar
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Estilos del modal
    const modalStyles = `
        <style id="modalStyles">
            #gameEndModal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 10000;
                animation: modalFadeIn 0.3s ease-out;
            }
            
            .modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.8);
                backdrop-filter: blur(5px);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .modal-content {
                background: #ffffff;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                max-width: 500px;
                width: 100%;
                max-height: 90vh;
                overflow-y: auto;
            }
            
            .modal-header {
                background: linear-gradient(45deg, #667eea, #764ba2);
                color: white;
                padding: 25px;
                border-radius: 18px 18px 0 0;
                text-align: center;
            }
            
            .modal-body {
                padding: 30px;
            }
            
            .score-display {
                text-align: center;
                margin-bottom: 30px;
                background: linear-gradient(45deg, #4CAF50, #45a049);
                border-radius: 15px;
                padding: 20px;
                color: white;
            }
            
            .final-score {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .score-value {
                font-size: 3rem;
                font-weight: 800;
            }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 15px;
                margin-bottom: 25px;
            }
            
            .stat-item {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 15px;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .stat-icon {
                font-size: 1.5rem;
            }
            
            .performance-section {
                margin-bottom: 20px;
            }
            
            .performance-bar {
                background: #e9ecef;
                border-radius: 25px;
                height: 30px;
                position: relative;
                overflow: hidden;
            }
            
            .performance-fill {
                height: 100%;
                background: linear-gradient(45deg, #4CAF50, #45a049);
                border-radius: 25px;
                transition: width 1s ease-out;
            }
            
            .performance-text {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                color: white;
                font-weight: 600;
            }
            
            .motivational-message {
                background: #e3f2fd;
                border-radius: 12px;
                padding: 20px;
                text-align: center;
                border-left: 4px solid #667eea;
            }
            
            .modal-footer {
                padding: 0 30px 30px 30px;
                display: flex;
                gap: 15px;
                justify-content: center;
            }
            
            .btn-modal {
                padding: 12px 24px;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                min-width: 140px;
            }
            
            .btn-primary {
                background: linear-gradient(45deg, #4CAF50, #45a049);
                color: white;
            }
            
            .btn-secondary {
                background: linear-gradient(45deg, #6c757d, #5a6268);
                color: white;
            }
            
            @keyframes modalFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
        </style>
    `;
    
    document.head.insertAdjacentHTML('beforeend', modalStyles);
    document.body.appendChild(modal);
}

function getPerformanceText(accuracy) {
    if (accuracy >= 90) return "¡Increíble!";
    if (accuracy >= 75) return "¡Excelente!";
    if (accuracy >= 60) return "¡Muy bien!";
    if (accuracy >= 40) return "No está mal";
    return "Sigue intentando";
}

function getMotivationalMessage(accuracy, score) {
    const messages = {
        high: ["¡Eres un artista natural!", "¡Precisión increíble!", "¡Técnica perfecta!"],
        med: ["¡Buen trabajo!", "¡Sigue mejorando!", "¡Gran esfuerzo!"],
        low: ["¡Buen comienzo!", "La práctica hace al maestro", "¡Sigue jugando!"]
    };
    
    let category = accuracy >= 75 ? 'high' : accuracy >= 40 ? 'med' : 'low';
    const list = messages[category];
    return list[Math.floor(Math.random() * list.length)];
}

function closeGameEndModal() {
    const modal = document.getElementById('gameEndModal');
    const styles = document.getElementById('modalStyles');
    
    if (modal) {
        modal.remove();
        if (styles) styles.remove();
    }
}

function stopGame() {
    gameActive = false;
    clearInterval(gameTimer);
    updateButtons(false);
    updateUI();
    showMessage('Juego detenido', 'info');
}

function skipWord() {
    if (!gameActive) return;
    showMessage('Palabra saltada', 'info');
    setTimeout(nextRound, 1000);
}

// Drawing functions
function startDrawing(e) {
    if (!gameState.isInitialized) return;
    
    isDrawing = true;
    const coords = getCoords(e);
    if (coords) {
        ctx.beginPath();
        ctx.moveTo(coords.x, coords.y);
    }
}

function draw(e) {
    if (!isDrawing || !gameState.isInitialized) return;
    
    const coords = getCoords(e);
    if (coords) {
        ctx.lineTo(coords.x, coords.y);
        ctx.stroke();
    }
}

function stopDrawing() {
    if (!isDrawing) return;
    isDrawing = false;
    
    if (gameActive && gameState.isInitialized) {
        setTimeout(() => {
            if (!isDrawing && gameActive) {
                analyzeDoodle();
            }
        }, 2000);
    }
}

function getCoords(e) {
    if (!canvas) return null;
    const rect = canvas.getBoundingClientRect();
    return {
        x: (e.clientX - rect.left) * (canvas.width / rect.width),
        y: (e.clientY - rect.top) * (canvas.height / rect.height)
    };
}

async function analyzeDoodle() {
    if (!gameActive || !canvas) return;
    
    const now = Date.now();
    const delay = 4000;
    
    if (now - gameState.lastPrediction < delay) {
        console.log('Esperando límite de velocidad...');
        return;
    }
    
    if (gameState.apiActive) {
        console.log('Llamada API en progreso');
        return;
    }
    
    gameState.apiActive = true;
    gameState.lastPrediction = now;
    
    try {
        const imageData = canvas.toDataURL('image/png', 0.7);
        showPredictions([{label: 'Analizando...', confidence: '...', loading: true}]);
        
        const response = await fetch('api-vision.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ image: imageData })
        });
        
        if (!response.ok) {
            if (response.status === 429) {
                throw new Error('RATE_LIMIT');
            }
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success && result.predictions) {
            showPredictions(result.predictions);
            checkMatch(result.predictions);
        } else {
            showPredictions([{label: 'No se detectaron objetos claros', confidence: '?', error: true}]);
        }
        
    } catch (error) {
        console.error('Error de API:', error.message);
        
        if (error.message === 'RATE_LIMIT') {
            showPredictions([{label: 'Demasiadas peticiones - espera un momento', confidence: '429', error: true}]);
            gameState.lastPrediction = Date.now() + 5000;
        } else {
            showPredictions([{label: 'Error de conexión', confidence: 'X', error: true}]);
        }
    } finally {
        gameState.apiActive = false;
    }
}

// Función mejorada de coincidencias
function checkMatch(predictions) {
    if (!gameActive || !predictions) return;
    
    const target = currentWord.toLowerCase();
    let bestMatch = null;
    let bestPoints = 0;
    let matchType = '';
    
    for (const pred of predictions) {
        const label = pred.label.toLowerCase();
        const original = pred.original ? pred.original.toLowerCase() : '';
        let points = 0;
        let type = '';
        
        // Coincidencia exacta
        if (label === target) {
            points = Math.max(50, Math.round(pred.confidence / 2) + timeLeft + 20);
            type = 'exact';
        }
        // Buscar en sinónimos 
        else if (wordMappings[target]) {
            const synonyms = wordMappings[target];
            for (const synonym of synonyms) {
                if (label.includes(synonym.toLowerCase()) || 
                    original.includes(synonym.toLowerCase()) ||
                    synonym.toLowerCase().includes(label)) {
                    points = Math.max(40, Math.round(pred.confidence / 3) + timeLeft + 15);
                    type = 'synonym';
                    break;
                }
            }
        }
        
        // Buscar en categorías
        if (points === 0) {
            for (const [category, items] of Object.entries(categories)) {
                if (items.includes(target)) {
                    for (const item of items) {
                        if (wordMappings[item]) {
                            const itemSynonyms = wordMappings[item];
                            for (const synonym of itemSynonyms) {
                                if (label.includes(synonym.toLowerCase()) || 
                                    original.includes(synonym.toLowerCase())) {
                                    points = Math.max(30, Math.round(pred.confidence / 4) + timeLeft + 10);
                                    type = 'category';
                                    break;
                                }
                            }
                        }
                        if (points > 0) break;
                    }
                }
                if (points > 0) break;
            }
        }
        
        // Coincidencia parcial
        if (points === 0 && (label.includes(target) || target.includes(label))) {
            points = Math.max(20, Math.round(pred.confidence / 5) + timeLeft + 5);
            type = 'partial';
        }
        
        if (points > bestPoints) {
            bestMatch = pred;
            bestPoints = points;
            matchType = type;
        }
    }
    
    if (bestMatch && bestPoints > 0) {
        score += bestPoints;
        gameState.correctGuesses++;
        
        let message = '';
        switch(matchType) {
            case 'exact':
                message = `¡Perfecto! "${bestMatch.label}"`;
                break;
            case 'synonym':
                message = `¡Correcto! "${bestMatch.label}" es similar a "${currentWord}"`;
                break;
            case 'category':
                message = `¡Bien! "${bestMatch.label}" está relacionado con "${currentWord}"`;
                break;
            case 'partial':
                message = `¡Cerca! "${bestMatch.label}" se parece a "${currentWord}"`;
                break;
        }
        
        const pointsMsg = ` (+${bestPoints} puntos)`;
        
        console.log(`Coincidencia encontrada: ${bestMatch.label} (+${bestPoints} puntos)`);
        showMessage(`${message}${pointsMsg}`, 'success');
        
        clearInterval(gameTimer);
        
        if (currentRound >= totalRounds) {
            setTimeout(endGame, 2500);
        } else {
            setTimeout(nextRound, 2500);
        }
        return;
    }
}

function showPredictions(predictions) {
    const container = document.getElementById('predictionsList');
    if (!container) return;
    
    if (!predictions || predictions.length === 0) {
        container.innerHTML = '<div class="prediction-placeholder">No se detectaron objetos</div>';
        return;
    }
    
    let html = '';
    predictions.forEach(pred => {
        const conf = pred.loading ? pred.confidence : `${pred.confidence}%`;
        const errorStyle = pred.error ? 'style="color: #e53e3e;"' : '';
        
        html += `<div class="prediction-item" ${errorStyle}>
            <span class="prediction-label">${pred.label}</span>
            <span class="prediction-confidence">${conf}</span>
        </div>`;
    });
    
    container.innerHTML = html;
}

function clearCanvas() {
    if (!ctx || !canvas) return;
    
    ctx.fillStyle = 'white';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    const container = document.getElementById('predictionsList');
    if (container) {
        container.innerHTML = '<div class="prediction-placeholder">Dibuja algo para ver predicciones...</div>';
    }
}

function updateUI() {
    updateElement('timer', timeLeft);
    updateElement('score', score);
    updateElement('round', `${currentRound}/${totalRounds}`);
    updateElement('currentWord', gameActive ? currentWord : 'Presiona Iniciar');
}

function updateButtons(gameStarted) {
    updateButton('startBtn', !gameStarted);
    updateButton('skipBtn', gameStarted);
    updateButton('stopBtn', gameStarted);
}

function updateElement(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function updateButton(id, enabled) {
    const btn = document.getElementById(id);
    if (btn) btn.disabled = !enabled;
}

function showMessage(message, type = 'info') {
    const existing = document.getElementById('tempMessage');
    if (existing) existing.remove();
    
    const msgDiv = document.createElement('div');
    msgDiv.id = 'tempMessage';
    
    const colors = {
        success: 'background: #4CAF50; color: white;',
        error: 'background: #f44336; color: white;',
        info: 'background: #2196F3; color: white;'
    };
    
    msgDiv.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        z-index: 10000;
        ${colors[type] || colors.info}
    `;
    
    msgDiv.textContent = message;
    document.body.appendChild(msgDiv);
    
    setTimeout(() => {
        if (msgDiv && msgDiv.parentNode) {
            msgDiv.remove();
        }
    }, 4000);
}

// Cerrar modal con Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('gameEndModal');
        if (modal) closeGameEndModal();
    }
});

console.log('Juego Quick Draw cargado2');
