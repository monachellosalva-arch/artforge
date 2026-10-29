<?php
/**
 * ArtForge v2 — générateur d'images stylisées multi-utilisateurs
 * PHP 7.4+ / 8.x — PDO_SQLite + cURL
 *
 * Pipeline strictement conforme au protocole Agnes :
 *   - img2txt : POST /v1/chat/completions (agnes-3.0-flash, vision)
 *   - txt2img : POST /v1/images/generations (agnes-image-2.5-flash)
 *   - ZÉRO img2img (extra_body.image n'est jamais envoyé)
 *
 * Auth : pseudo + PIN 4 chiffres, session PHP, espace privé par utilisateur.
 */

declare(strict_types=1);

@set_time_limit(900);
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '900');

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '' || $basePath === '.') $basePath = '';
$GLOBALS['basePath'] = $basePath;

// MODIFICATION POUR RENDER : On utilise le disque persistant
// Si tu testes en local, tu peux remettre __DIR__ . '/data'
define('DATA_ROOT', '/var/data');
define('DB_PATH',   DATA_ROOT . '/artforge.sqlite');

define('AGNES_BASE', 'https://apihub.agnes-ai.com/v1');
define('AGNES_TEXT_MODEL', 'agnes-3.0-flash');
define('AGNES_TEXT_MODEL_FALLBACK', 'agnes-2.5-flash');
define('AGNES_IMG_MODEL', 'agnes-image-2.5-flash');

/* ============================================================
   SESSION
============================================================ */
$action = $_GET['action'] ?? '';
$needsWrite = in_array($action, ['login', 'logout'], true);
if ($needsWrite) {
    session_start();
} else {
    if (PHP_VERSION_ID >= 70000) session_start(['read_and_close' => true]);
    else { session_start(); session_write_close(); }
}
$currentUserId = $_SESSION['user_id'] ?? null;

/* ============================================================
   BASE DE DONNÉES
============================================================ */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!is_dir(DATA_ROOT)) @mkdir(DATA_ROOT, 0755, true);
    if (!is_file(DATA_ROOT . '/.htaccess')) {
        @file_put_contents(DATA_ROOT . '/.htaccess',
            "Options -Indexes\n<FilesMatch \"\\.(sqlite|sqlite3|db|log|key_offset)$\">\nRequire all denied\n</FilesMatch>\n");
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=10000');

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        pin_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE TABLE IF NOT EXISTS styles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        slug TEXT NOT NULL,
        name TEXT NOT NULL,
        prompt TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, slug)
      );
      CREATE INDEX IF NOT EXISTS idx_styles_user ON styles(user_id);
      CREATE TABLE IF NOT EXISTS generations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        style_slug TEXT,
        user_input TEXT,
        used_image INTEGER DEFAULT 0,
        vision_desc TEXT,
        final_prompt TEXT,
        image_url TEXT,
        parent_id INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_gen_user ON generations(user_id);
    ");

    /* Seed global des 4 styles par défaut (sera copié à chaque inscription) */
    $defaults = defaultStyles();
    $stmt = $pdo->prepare("INSERT INTO styles (user_id, slug, name, prompt) VALUES (0, ?, ?, ?) ON CONFLICT(user_id, slug) DO NOTHING");
    foreach ($defaults as $s) $stmt->execute([$s['slug'], $s['name'], $s['prompt']]);

    return $pdo;
}

/** Les 4 styles par défaut, formatés selon la structure Agnes */
function defaultStyles(): array
{
    return [
        [
            'slug' => 'wes_anderson',
            'name' => 'Wes Anderson — cinéma argentique',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Wes Anderson's cinematic photography.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: 35mm Kodak Portra 400 film grain, photorealistic cinematic still, Art Deco × Gaudí geometry.\n".
"- Palette: powder blue, hot pink, mustard yellow, beige, turquoise.\n".
"- Lighting: soft diffused natural light.\n".
"- Composition: perfectly symmetrical centered composition, flat 40mm telephoto perspective.\n".
"- Subject: deadpan expression, meticulously designed pastel costumes.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim (identity, pose, clothing). Only restyle.\n".
"- No digital art, no illustration, no 3D render, no anime.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
        [
            'slug' => 'moebius',
            'name' => 'Manga — Mœbius (ligne claire)',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Jean Giraud \"Mœbius\" comic art.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: ligne claire (clear line) drawing, European bande dessinée aesthetic, fine confident ink lines, Moebius sci-fi comic art.\n".
"- Palette: muted pastel watercolor (sandy yellows, dusty blues, pale pinks, mineral greens).\n".
"- Composition: surreal vast landscapes, minimalist backgrounds, clean flat color fills, delicate hatching.\n".
"- Lighting: soft even diffuse light, no harsh shadows.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim. Only restyle.\n".
"- No photograph, no 3D render, no anime.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
        [
            'slug' => 'akira',
            'name' => 'Manga — Akira (Otomo)',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Katsuhiro Otomo's \"Akira\" manga.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: high-contrast black and white ink drawing, Katsuhiro Otomo manga aesthetic, meticulous cross-hatching, screentone textures, sharp angular features.\n".
"- Scene: dense Neo-Tokyo urban detail, dramatic perspective, post-apocalyptic cyberpunk atmosphere.\n".
"- Lighting: hard directional shading, stark black-and-white contrast.\n".
"- Composition: dynamic perspective, detailed backgrounds.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim. Only restyle.\n".
"- No photograph, no color, no 3D render.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
        [
            'slug' => 'schiele',
            'name' => 'Egon Schiele — expressionnisme (non nu)',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Egon Schiele's expressionist painting.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: expressionist oil painting, Egon Schiele aesthetic, elongated distorted bodies, angular gaunt faces, nervous jagged line work, bold outlines, Vienna Secession era.\n".
"- Palette: sickly pale greens and ochres mixed with muted reds and blacks.\n".
"- Lighting: raw emotional intensity, flat stark background.\n".
"- Composition: stark flat background, bold outlines.\n".
"STRICT CONSTRAINT: ALL SUBJECTS MUST BE FULLY CLOTHED — NO NUDITY, NO EROTICISM. If the source mentions nudity or partial undress, dress the subject in appropriate early-1900s Vienna clothing.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim (identity, pose). Only restyle.\n".
"- No photograph, no 3D render.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
    ];
}

/* ============================================================
   HELPERS
============================================================ */
function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function sanitizeKeys(array $keys): array
{
    $clean = [];
    foreach ($keys as $k) {
        if (!is_string($k)) continue;
        $k = trim($k);
        if (strlen($k) < 20) continue;
        if (strpos($k, 'sk-') !== 0) continue;
        $clean[] = $k;
    }
    return array_values(array_unique($clean));
}
function ensureUserDir(int $userId): string
{
    $dir = DATA_ROOT . '/u' . $userId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir . '/images')) @mkdir($dir . '/images', 0755, true);
    return $dir;
}

/**
 * Rotation déterministe de l'offset des clés API, persistante entre processus.
 * Chaque appel de getKeyOffset() renvoie une valeur 0..n-1, incrémentée atomiquement
 * via un petit fichier sous flock. Ceci garantit que N requêtes concurrentes
 * (N processus PHP-FPM séparés) tapent N clés différentes.
 */
function getKeyOffset(int $n): int
{
    static $fallback = 0;
    if ($n <= 0) return 0;
    if (!is_dir(DATA_ROOT)) @mkdir(DATA_ROOT, 0755, true);

    $file = DATA_ROOT . '/.key_offset';
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        /* Fallback sans lock : round-robin local au processus */
        return ($fallback++) % $n;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ($fallback++) % $n;
    }
    $content = (int) trim((string) stream_get_contents($fp));
    $offset  = $content % $n;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string) ($content + 1));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $offset;
}

/* ============================================================
   AGNES HTTP
============================================================ */
class AgnesHttpException extends RuntimeException
{
    public $httpCode;
    public function __construct(string $message, int $httpCode = 0)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

function agnesCall(string $path, array $payload, string $apiKey, int $timeout = 180): array
{
    $ch = curl_init(AGNES_BASE . $path);
    if ($ch === false) throw new RuntimeException('curl_init failed');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Curl: ' . $err);
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? substr($res, 0, 250);
        throw new AgnesHttpException('Agnes HTTP ' . $code . ': ' . $msg, $code);
    }
    return is_array($data) ? $data : [];
}

/**
 * Multi-clés avec rotation round-robin persistante.
 * Chaque appel démarre sur une clé différente (offset partagé entre processus),
 * puis parcourt l'anneau complet en cas d'échec.
 */
function agnesCallMulti(string $path, array $payload, array $keys, int $timeout): array
{
    $n = count($keys);
    if ($n === 0) throw new RuntimeException('Aucune clé API disponible');

    $start   = getKeyOffset($n);
    $rotated = array_merge(array_slice($keys, $start), array_slice($keys, 0, $start));

    $lastErr = null;
    $isChat  = (strpos($path, 'chat/completions') !== false);
    $models  = $isChat ? [AGNES_TEXT_MODEL, AGNES_TEXT_MODEL_FALLBACK] : [null];

    foreach ($models as $m) {
        foreach ($rotated as $key) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $p = $payload;
                    if ($m !== null) $p['model'] = $m;
                    return agnesCall($path, $p, $key, $timeout);
                } catch (Throwable $e) {
                    $lastErr  = $e;
                    $httpCode = ($e instanceof AgnesHttpException) ? $e->httpCode : 0;
                    if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) break;
                    if ($attempt === 0) usleep(500000);
                }
            }
        }
    }
    throw $lastErr ?: new RuntimeException('Toutes les clés ont échoué');
}

/* ============================================================
   TÉLÉCHARGEMENT IMAGE (local)
============================================================ */
function downloadImage(string $url, int $userId, string $label): string
{
    $dir = ensureUserDir($userId) . '/images';
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);
    if ($safe === '') $safe = 'img';

    if (preg_match('#^data:image/(\w+);base64,#', $url, $m)) {
        $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
        $fn  = $safe . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $bin = base64_decode(substr($url, strlen($m[0])), true);
        if ($bin === false || strlen($bin) < 100) throw new RuntimeException('base64 invalide');
        file_put_contents($dir . '/' . $fn, $bin);
    } else {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http','https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL invalide');
        }
        $ext = 'png';
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && preg_match('/\.(jpg|jpeg|png|webp)$/i', $path, $m)) {
            $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
        }
        $fn  = $safe . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $fp  = fopen($dir . '/' . $fn, 'wb');
        $ch  = curl_init($url);
        $bytes = 0;
        curl_setopt_array($ch, [
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use ($fp, &$bytes) {
                $bytes += strlen($chunk);
                if ($bytes > 25 * 1024 * 1024) return 0;
                return fwrite($fp, $chunk);
            },
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT         => 120,
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        if (!$ok || $code >= 400 || filesize($dir . '/' . $fn) < 100) {
            @unlink($dir . '/' . $fn);
            throw new RuntimeException('Téléchargement échoué (' . $code . ')');
        }
    }
    return $GLOBALS['basePath'] . '/data/u' . $userId . '/images/' . $fn;
}

/* ============================================================
   SYSTEM PROMPTS INTERNES
============================================================ */
function visionSystem(): string
{
    return
"You are a professional visual describer. Your output will be used to re-generate the SAME scene in a different art style.\n".
"Describe ONLY what you see, exhaustively and neutrally. Do NOT stylize, do NOT invent.\n".
"Structure your answer with these sections (headings in French, content in French):\n".
"1. PERSONNES — For EACH person: apparent age, face shape, eyes, nose, lips, skin, hair (color/texture/length), body type, posture, expression. Describe clothing item by item with precise colors and materials.\n".
"2. DÉCOR — Location, architecture, furniture, objects, textures, materials.\n".
"3. ÉCLAIRAGE — Light sources, direction, quality, shadows.\n".
"4. ATMOSPHÈRE — Mood, dominant colors, color palette (give hex approximations if possible).\n".
"Rules:\n".
"- If several people are visible, describe each one separately.\n".
"- Do not group people.\n".
"- Be surgical: every detail may be reused verbatim.\n".
"- Length: 180–260 words.";
}

/**
 * Amélioration d'un prompt de style : on RÉÉCRIT le template, on ne l'exécute pas.
 * Sans cette précision, le modèle lit « Convert the given scene… » et invente une scène.
 */
function improveStyleSystem(): string
{
    return
"You are an expert prompt engineer specialised in ART-STYLE SYSTEM PROMPTS for AI image generation.\n".
"Your job is to IMPROVE A TEMPLATE, not to execute it.\n".
"\n".
"CONTEXT:\n".
"The input is a SYSTEM PROMPT / TEMPLATE used by a pipeline. This template teaches an AI how to convert ANY user scene into a final image prompt. It is REUSABLE for any subject (portraits, landscapes, objects, animals, architecture, abstract concepts...).\n".
"\n".
"YOUR TASK:\n".
"Rewrite the template to make it clearer, more precise, and more effective — while keeping it GENERIC and REUSABLE.\n".
"\n".
"CRITICAL RULES — VIOLATION = FAILURE:\n".
"1. DO NOT execute the template. DO NOT generate a scene. DO NOT invent subjects, characters, landscapes, or objects.\n".
"2. The output MUST remain a reusable TEMPLATE with the same structure (ROLE / TASK / PROMPT STRUCTURE / ALWAYS INCLUDE / CONSTRAINTS or equivalent).\n".
"3. Preserve the original artist / style identity completely. Do NOT change the artist. Do NOT add other artists.\n".
"4. Keep the SAME LANGUAGE as the input (French or English).\n".
"5. You MAY add: precise colour palettes (with hex), technique details, lighting rules, composition rules, quality guards, and a clear instruction to preserve the user's subject verbatim.\n".
"6. Structure the prompt in Agnes-recommended order: Subject → Scene → Style → Lighting → Composition → Quality.\n".
"7. Do NOT add scene-specific words. The template must work whether the user inputs 'a portrait of a woman' or 'a spaceship'.\n".
"8. Output ONLY the rewritten template. No preamble. No explanation. No quotation marks. No markdown fences.";
}

/* ============================================================
   ROUTAGE AJAX
============================================================ */
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $readOnly = ['me', 'get_styles', 'history'];
    if (!in_array($action, $readOnly, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'Méthode HTTP invalide (POST requis)'], 405);
    }

    try {
        switch ($action) {

            /* ---------- AUTH ---------- */
            case 'login': {
                $b = body();
                $user = trim((string)($b['username'] ?? ''));
                $pin  = trim((string)($b['pin'] ?? ''));
                if (!preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $user)) {
                    respond(['error' => 'Pseudo invalide (2-32 car., a-z 0-9 _ -)'], 400);
                }
                if (!preg_match('/^\d{4}$/', $pin)) {
                    respond(['error' => 'PIN : 4 chiffres requis'], 400);
                }
                $pdo = db();
                $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
                $stmt->execute([$user]);
                $row = $stmt->fetch();
                if ($row) {
                    if (!password_verify($pin, $row['pin_hash'])) respond(['error' => 'PIN incorrect'], 403);
                    $uid = (int)$row['id'];
                } else {
                    $hash = password_hash($pin, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (username, pin_hash) VALUES (?, ?)');
                    $stmt->execute([$user, $hash]);
                    $uid = (int)$pdo->lastInsertId();
                    /* Copier les 4 styles par défaut dans l'espace du nouvel utilisateur */
                    $copy = $pdo->prepare("INSERT OR IGNORE INTO styles (user_id, slug, name, prompt)
                                           SELECT ?, slug, name, prompt FROM styles WHERE user_id = 0");
                    $copy->execute([$uid]);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = $uid;
                $_SESSION['username'] = $user;
                ensureUserDir($uid);
                respond(['ok' => true, 'user' => $user, 'user_id' => $uid]);
            }

            case 'logout':
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                }
                session_destroy();
                respond(['ok' => true]);

            case 'me':
                respond([
                    'user' => $_SESSION['username'] ?? null,
                    'user_id' => $currentUserId,
                ]);

            /* ---------- STYLES (par utilisateur) ---------- */
            case 'get_styles': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $rows = db()->prepare("SELECT id, slug, name, prompt, updated_at FROM styles WHERE user_id = ? ORDER BY id");
                $rows->execute([$currentUserId]);
                respond(['ok' => true, 'styles' => $rows->fetchAll()]);
            }

            case 'save_style': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $id     = (int)($b['id'] ?? 0);
                $name   = trim((string)($b['name'] ?? ''));
                $prompt = trim((string)($b['prompt'] ?? ''));
                if ($name === '' || $prompt === '') respond(['error' => 'name et prompt requis'], 400);
                if ($id > 0) {
                    $stmt = db()->prepare("UPDATE styles SET name = ?, prompt = ?, updated_at = datetime('now')
                                           WHERE id = ? AND user_id = ?");
                    $stmt->execute([$name, $prompt, $id, $currentUserId]);
                } else {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', $name));
                    $slug = trim($slug, '_') . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
                    $stmt = db()->prepare("INSERT INTO styles (user_id, slug, name, prompt) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$currentUserId, $slug, $name, $prompt]);
                }
                respond(['ok' => true]);
            }

            case 'delete_style': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $id = (int)($b['id'] ?? 0);
                $stmt = db()->prepare("DELETE FROM styles WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $currentUserId]);
                respond(['ok' => true]);
            }

            /* ---------- AMÉLIORER UN PROMPT DE STYLE ---------- */
            case 'improve_style': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $current = trim((string)($b['prompt'] ?? ''));
                $keys = sanitizeKeys($b['keys'] ?? []);
                if ($current === '' || !$keys) respond(['error' => 'prompt et keys requis'], 400);

                /* Délimiteurs explicites : le modèle voit « voici le matériau, pas la consigne » */
                $userMsg =
                    "=== TEMPLATE À AMÉLIORER (À RÉÉCRIRE, NE PAS EXÉCUTER) ===\n" .
                    $current .
                    "\n=== FIN DU TEMPLATE ===\n\n" .
                    "Réécris CE TEMPLATE en conservant son rôle d'instruction générique.\n" .
                    "NE génère AUCUNE scène. NE choisis AUCUN sujet.\n" .
                    "Retourne uniquement le template amélioré, dans la même langue.";

                $payload = [
                    'model' => AGNES_TEXT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => improveStyleSystem()],
                        ['role' => 'user',   'content' => $userMsg],
                    ],
                    'temperature' => 0.4,
                    'max_tokens'  => 1200,
                    'reasoning_effort' => 'none',
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ];
                $data = agnesCallMulti('/chat/completions', $payload, $keys, 120);
                $out  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
                $out  = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $out);
                if ($out === '') throw new RuntimeException('Réponse IA vide');
                respond(['ok' => true, 'prompt' => trim($out)]);
            }

            /* ---------- VISION (img → txt) ---------- */
            case 'analyze': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $image = (string)($b['image'] ?? '');
                $keys  = sanitizeKeys($b['keys'] ?? []);
                if ($image === '' || !$keys) respond(['error' => 'image et keys requis'], 400);
                if (strlen($image) > 18000000) respond(['error' => 'Image trop volumineuse'], 413);

                $payload = [
                    'model' => AGNES_TEXT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => visionSystem()],
                        ['role' => 'user',   'content' => [
                            ['type' => 'text', 'text' => 'Décris cette image.'],
                            ['type' => 'image_url', 'image_url' => ['url' => $image]],
                        ]],
                    ],
                    'temperature' => 0.2,
                    'max_tokens'  => 2000,
                    'reasoning_effort' => 'none',
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ];
                try {
                    $data = agnesCallMulti('/chat/completions', $payload, $keys, 240);
                    $txt  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
                    if ($txt === '') throw new RuntimeException('Réponse vide');
                    respond(['ok' => true, 'content' => $txt]);
                } catch (Throwable $e) {
                    respond(['error' => 'Analyse échouée : ' . $e->getMessage()], 500);
                }
            }

            /* ---------- PROMPT FINAL (txt → txt) ---------- */
            case 'generate_prompt': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $system  = trim((string)($b['system'] ?? ''));
                $userMsg = trim((string)($b['user'] ?? ''));
                $keys    = sanitizeKeys($b['keys'] ?? []);
                $maxTok  = max(80, min(2000, (int)($b['max_tokens'] ?? 500)));
                $temp    = max(0.0, min(1.5, (float)($b['temperature'] ?? 0.75)));
                if ($system === '' || $userMsg === '' || !$keys) {
                    respond(['error' => 'system, user et keys requis'], 400);
                }

                $payload = [
                    'model' => AGNES_TEXT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $userMsg],
                    ],
                    'temperature' => $temp,
                    'max_tokens'  => $maxTok,
                    'reasoning_effort' => 'none',
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ];
                try {
                    $data = agnesCallMulti('/chat/completions', $payload, $keys, 120);
                    $out  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
                    $out  = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $out);
                    $out  = trim($out, "\"' \n\r\t");
                    if ($out === '') throw new RuntimeException('Réponse vide');
                    respond(['ok' => true, 'content' => $out]);
                } catch (Throwable $e) {
                    respond(['error' => 'Prompt échoué : ' . $e->getMessage()], 500);
                }
            }

            /* ---------- IMAGE (txt → img) ---------- */
            case 'generate_image': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $prompt = trim((string)($b['prompt'] ?? ''));
                $keys   = sanitizeKeys($b['keys'] ?? []);
                $label  = (string)($b['label'] ?? 'img');
                $size   = (string)($b['size']  ?? '2K');
                $ratio  = (string)($b['ratio'] ?? '9:16');
                if ($prompt === '' || !$keys) respond(['error' => 'prompt et keys requis'], 400);
                if (strlen($prompt) > 20000) respond(['error' => 'Prompt trop long'], 413);
                if (!in_array($size, ['1K','2K','3K','4K'], true)) $size = '2K';
                if (!in_array($ratio, ['1:1','3:4','4:3','16:9','9:16','2:3','3:2','21:9'], true)) $ratio = '9:16';

                $payload = [
                    'model'  => AGNES_IMG_MODEL,
                    'prompt' => mb_substr($prompt, 0, 5000),
                    'size'   => $size,
                    'ratio'  => $ratio,
                    'extra_body' => ['response_format' => 'url'],
                ];
                try {
                    $data = agnesCallMulti('/images/generations', $payload, $keys, 240);
                    $url  = $data['data'][0]['url'] ?? null;
                    if (!$url && isset($data['data'][0]['b64_json'])) {
                        $url = 'data:image/png;base64,' . $data['data'][0]['b64_json'];
                    }
                    if (!$url) throw new RuntimeException('Pas d\'URL dans la réponse');
                    $local = downloadImage($url, $currentUserId, $label);
                    respond(['ok' => true, 'url' => $local, 'remote' => $url]);
                } catch (Throwable $e) {
                    respond(['error' => 'Image échouée : ' . $e->getMessage()], 500);
                }
            }

            /* ---------- LOG ---------- */
            case 'log_generation': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $stmt = db()->prepare(
                    "INSERT INTO generations (user_id, style_slug, user_input, used_image, vision_desc, final_prompt, image_url, parent_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $currentUserId,
                    (string)($b['style_slug'] ?? ''),
                    (string)($b['user_input'] ?? ''),
                    (int)($b['used_image'] ?? 0),
                    (string)($b['vision_desc'] ?? ''),
                    (string)($b['final_prompt'] ?? ''),
                    (string)($b['image_url'] ?? ''),
                    isset($b['parent_id']) ? (int)$b['parent_id'] : null,
                ]);
                respond(['ok' => true, 'id' => (int)db()->lastInsertId()]);
            }

            /* ---------- HISTORIQUE (par utilisateur) ---------- */
            case 'history': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $stmt = db()->prepare(
                    "SELECT id, style_slug, user_input, used_image, final_prompt, image_url, parent_id, created_at
                     FROM generations WHERE user_id = ? ORDER BY id DESC LIMIT 24"
                );
                $stmt->execute([$currentUserId]);
                respond(['ok' => true, 'items' => $stmt->fetchAll()]);
            }

            /* ---------- TEST CLÉS ---------- */
            case 'test_keys': {
                $b = body();
                $keys = array_slice(sanitizeKeys($b['keys'] ?? []), 0, 30);
                if (!$keys) respond(['error' => 'Aucune clé valide'], 400);

                $mh = curl_multi_init(); $handles = [];
                foreach ($keys as $i => $key) {
                    $ch = curl_init(AGNES_BASE . '/chat/completions');
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode([
                            'model' => AGNES_TEXT_MODEL,
                            'messages' => [['role' => 'user', 'content' => 'ok']],
                            'max_tokens' => 1,
                        ]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]);
                    curl_multi_add_handle($mh, $ch);
                    $handles[$i] = $ch;
                }
                do {
                    curl_multi_exec($mh, $running);
                    if ($running) curl_multi_select($mh, 1.0);
                } while ($running > 0);

                $results = [];
                foreach ($handles as $i => $ch) {
                    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $ok = ($code >= 200 && $code < 300) || $code === 429;
                    $results[] = ['key' => $keys[$i], 'ok' => $ok, 'status' => $code];
                    curl_multi_remove_handle($mh, $ch); curl_close($ch);
                }
                curl_multi_close($mh);
                respond(['ok' => true, 'results' => $results]);
            }

            default:
                respond(['error' => 'Action inconnue : ' . $action], 404);
        }
    } catch (Throwable $e) {
        error_log('[ArtForge] ' . get_class($e) . ': ' . $e->getMessage());
        respond(['error' => $e->getMessage()], 500);
    }
    exit;
}

/* ============================================================
   HTML
============================================================ */
header('Content-Type: text/html; charset=utf-8');
$isLogged = $currentUserId !== null;
$username = $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ArtForge — générateur d'images stylisées</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0b0d12;--bg2:#11151d;--bg3:#171d27;--line:#293342;--line2:#3a485b;--ink:#f0f3f8;--ink2:#a9b4c5;--ink3:#718096;--gold:#e7bd76;--gold-dim:rgba(231,189,118,.12);--cyan:#7ccbd0;--cyan-dim:rgba(124,203,208,.11);--green:#76c7a0;--green-dim:rgba(118,199,160,.12);--red:#e47f82;--red-dim:rgba(228,127,130,.12);--radius:14px;--radius-sm:9px}
body{font:14px/1.55 system-ui,-apple-system,sans-serif;background:radial-gradient(ellipse at 82% -10%,rgba(124,203,208,.08),transparent 35%),var(--bg);color:var(--ink);min-height:100vh}
#topbar{position:fixed;top:0;left:0;right:0;min-height:58px;padding:.65rem 1.25rem;background:rgba(11,13,18,.94);backdrop-filter:blur(16px);border-bottom:1px solid var(--line);z-index:50;display:flex;align-items:center;gap:.8rem;font:12px ui-monospace,monospace}
#modeTag{padding:.22rem .65rem;border-radius:100px;border:1px solid var(--line);color:var(--ink3);font-weight:700;font-size:.68rem}
#modeTag.live{border-color:var(--green);color:var(--green)}
#globalStat{color:var(--ink2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.75rem}
#main{max-width:1200px;margin:0 auto;padding:5rem 1.2rem 130px}
h1{font-family:Georgia,serif;font-size:1.9rem;color:var(--gold);font-weight:400;letter-spacing:-.03em;margin-bottom:.3rem}
.sub{color:var(--ink2);font-size:.9rem;margin-bottom:1.4rem}
h2{color:var(--gold);font-size:1.15rem;margin:1.2rem 0 .7rem;font-weight:500}
.card{background:var(--bg2);border:1px solid var(--line);border-radius:var(--radius);padding:1.2rem;margin-bottom:1rem;box-shadow:0 8px 28px rgba(0,0,0,.12)}
label.lab{display:block;font-size:.68rem;color:var(--ink2);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin:.7rem 0 .35rem}
input[type=text],input[type=password],input[type=number],textarea,select{width:100%;background:#0d1118;border:1px solid #303b4b;border-radius:9px;padding:.7rem .85rem;font:13px system-ui,sans-serif;color:var(--ink);outline:none;transition:.15s;min-height:42px}
textarea{min-height:88px;line-height:1.65;resize:vertical;font-family:inherit}
input:focus,textarea:focus,select:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(231,189,118,.13)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
@media(max-width:600px){.row{grid-template-columns:1fr}}
.btn{min-height:42px;padding:.65rem 1.1rem;border-radius:9px;font:700 .82rem system-ui,sans-serif;cursor:pointer;border:1px solid;transition:.15s;display:inline-flex;align-items:center;justify-content:center;gap:.4rem}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-mega{min-height:52px;background:var(--gold);color:#15130f;border:none;font-size:.95rem;font-weight:800;width:100%;box-shadow:0 7px 24px rgba(231,189,118,.13);margin-top:1rem}
.btn-gold{background:var(--gold-dim);border-color:rgba(231,189,118,.5);color:var(--gold)}
.btn-cyan{background:var(--cyan-dim);border-color:var(--cyan);color:var(--cyan)}
.btn-red{background:var(--red-dim);border-color:var(--red);color:var(--red)}
.btn-slate{background:var(--bg3);border-color:var(--line);color:var(--ink2);font-size:.72rem}
.btn-mini{background:transparent;border:1px solid var(--line);color:var(--ink2);font-size:.66rem;padding:.32rem .7rem;border-radius:6px;cursor:pointer;font-family:inherit;transition:.15s;min-height:0}
.btn-mini:hover{color:var(--gold);border-color:var(--gold);background:var(--gold-dim)}
.tabs{display:flex;gap:.35rem;margin-bottom:1.2rem;flex-wrap:wrap}
.tab{padding:.65rem 1rem;border-radius:10px;border:1px solid var(--line);background:var(--bg2);color:var(--ink2);cursor:pointer;font-weight:600;font-size:.82rem;transition:.15s}
.tab.active{background:var(--gold-dim);border-color:var(--gold);color:var(--gold)}
.drop{border:2px dashed #3a4657;border-radius:12px;padding:1.3rem;text-align:center;cursor:pointer;position:relative;background:rgba(255,255,255,.015);transition:.15s}
.drop:hover{border-color:var(--gold);background:var(--gold-dim)}
.drop input{position:absolute;inset:0;opacity:0;cursor:pointer}
.drop .icon{font-size:1.8rem;opacity:.55;margin-bottom:.3rem}
.drop .txt{font-size:.82rem;color:var(--ink2)}
.preview{margin-top:.7rem;position:relative;display:inline-block;max-width:220px}
.preview img{max-width:100%;border-radius:9px;border:1px solid var(--line);display:block}
.preview .rm{position:absolute;top:4px;right:4px;background:rgba(228,127,130,.95);color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:.7rem}
.status{font:12px/1.5 system-ui,sans-serif;padding:.7rem .9rem;border-radius:9px;background:var(--bg3);color:var(--ink2);border-left:3px solid var(--line);word-break:break-word;margin-top:.5rem}
.status.ok{background:var(--green-dim);color:var(--green);border-left-color:var(--green)}
.status.err{background:var(--red-dim);color:var(--red);border-left-color:var(--red)}
.status.warn{background:rgba(231,189,118,.12);color:var(--gold);border-left-color:var(--gold)}
.step{display:flex;align-items:flex-start;gap:.6rem;padding:.7rem .9rem;background:var(--bg3);border:1px solid var(--line);border-radius:9px;margin-bottom:.5rem;font-size:.82rem}
.step .ico{flex-shrink:0;font-size:1.05rem}
.step .msg{flex:1;color:var(--ink2)}
.step.running .msg{color:var(--cyan)}
.step.done{border-left:3px solid var(--green)}
.step.error{border-left:3px solid var(--red);background:var(--red-dim)}
.step.error .msg{color:var(--red)}
.spinner{display:inline-block;width:12px;height:12px;border:2px solid rgba(231,189,118,.22);border-top-color:var(--gold);border-radius:50%;animation:spin .8s linear infinite;vertical-align:middle;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}
.result-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-top:1rem}
.img-card{background:var(--bg2);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column}
.img-card .img-wrap{padding:.5rem}
.img-card img{width:100%;display:block;border-radius:8px;background:var(--bg)}
.img-card .img-wrap .old{opacity:.55;margin-bottom:.5rem;border:1px solid var(--red);position:relative}
.img-card .img-wrap .old::after{content:'ancienne';position:absolute;top:4px;right:4px;background:var(--red);color:#fff;font-size:.6rem;padding:.15rem .4rem;border-radius:4px}
.img-card .img-wrap .new{border:2px solid var(--green)}
.img-card .info{padding:.6rem .75rem;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:.4rem}
.img-card .info .meta{font-size:.65rem;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em}
.img-card .info textarea{min-height:70px;font:11px/1.5 ui-monospace,monospace;margin:0}
.img-card .info .actions{display:flex;gap:.35rem;flex-wrap:wrap}
/* Placeholder lazy-load de la galerie */
.img-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:220px;background:repeating-linear-gradient(45deg,rgba(255,255,255,.015) 0 10px,transparent 10px 20px),linear-gradient(135deg,#0d1118,#171d27);border:1px dashed var(--line2);border-radius:8px;gap:.55rem;color:var(--ink3);padding:1rem;text-align:center}
.img-placeholder .ph-icon{font-size:1.8rem;opacity:.55}
.img-placeholder .ph-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.09em;font-weight:700}
.gallery-head{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.6rem;flex-wrap:wrap}
.gallery-head .hint{font-size:.75rem;color:var(--ink3)}
#apiPanel{position:fixed;bottom:0;left:0;right:0;background:rgba(17,21,29,.98);border-top:1px solid var(--line2);box-shadow:0 -10px 34px rgba(0,0,0,.25);z-index:40}
#apiHead{min-height:48px;padding:.75rem 1.2rem;cursor:pointer;display:flex;align-items:center;gap:.8rem;user-select:none}
#apiHead:hover{background:rgba(231,189,118,.04)}
#apiHead .lbl{font:700 .72rem ui-monospace;color:var(--gold);letter-spacing:.1em}
#apiBody{padding:1rem 1.2rem;border-top:1px solid var(--line);max-height:55vh;overflow-y:auto;display:none}
#apiBody.open{display:block}
#toast{position:fixed;top:68px;left:50%;transform:translateX(-50%) translateY(-140px);background:var(--bg2);border:1px solid var(--line);border-left:3px solid var(--gold);border-radius:11px;padding:.85rem 1.1rem;font-size:.82rem;z-index:200;opacity:0;transition:.3s;max-width:min(560px,92vw);text-align:center;box-shadow:0 8px 28px rgba(0,0,0,.45)}
#toast.on{transform:translateX(-50%) translateY(0);opacity:1}
#toast.ok{border-left-color:var(--green)}
#toast.err{border-left-color:var(--red)}
#toast.info{border-left-color:var(--cyan)}
.style-editor{background:var(--bg2);border:1px solid var(--line);border-radius:12px;padding:1rem;margin-bottom:.9rem}
.style-editor h4{display:flex;align-items:center;gap:.5rem;font-size:.95rem;margin-bottom:.5rem}
.style-editor .slug{font:600 .65rem ui-monospace;color:var(--ink3)}
.style-editor textarea{min-height:180px;font-family:ui-monospace,monospace;font-size:11.5px;line-height:1.6}
.style-editor .actions{display:flex;gap:.4rem;margin-top:.5rem;flex-wrap:wrap}
.pipeline-ctrl{display:flex;gap:.5rem;margin-top:.7rem;flex-wrap:wrap}
.pipeline-ctrl .btn{flex:1;min-width:120px}
#loginBox{max-width:420px;margin:6rem auto}
.hidden{display:none!important}
</style>
</head>
<body>

<div id="topbar">
  <span id="modeTag">NON CONFIGURÉ</span>
  <span id="globalStat">Ajoutez vos clés API pour commencer</span>
  <button class="btn-slate hidden" id="logoutBtn" style="margin-left:auto">↻ Déconnexion</button>
</div>

<!-- ================= LOGIN ================= -->
<div id="loginBox" class="card <?= $isLogged ? 'hidden' : '' ?>">
  <h1 style="text-align:center">ArtForge</h1>
  <p class="sub" style="text-align:center">Pseudo + PIN 4 chiffres · Connexion / Inscription en 1 étape</p>
  <label class="lab">Pseudo</label>
  <input type="text" id="loginUser" placeholder="Votre pseudo" autocomplete="username">
  <label class="lab">PIN (4 chiffres)</label>
  <input type="password" id="loginPin" maxlength="4" inputmode="numeric" placeholder="••••" autocomplete="current-password">
  <button class="btn-mega" id="loginBtn">Se connecter / Créer un compte</button>
  <div id="loginStatus" class="status" style="margin-top:.6rem"></div>
</div>

<div id="main" class="<?= $isLogged ? '' : 'hidden' ?>">
  <h1>ArtForge</h1>
  <p class="sub">Photo + prompt + style → image stylisée. Agnes IA · txt2img uniquement.</p>

  <div class="tabs">
    <button class="tab active" data-tab="create">🎨 Créer</button>
    <button class="tab" data-tab="admin">⚙️ Admin styles</button>
    <button class="tab" data-tab="history">🖼️ Ma bibliothèque</button>
  </div>

  <!-- ================= CRÉER ================= -->
  <section id="tab-create">
    <div class="card">
      <label class="lab">Style</label>
      <select id="styleSelect"></select>

      <label class="lab">Prompt / intention (optionnel)</label>
      <textarea id="promptInput" placeholder="Décris la scène, l'ambiance, le personnage… (laisse vide si tu fournis juste une photo)"></textarea>

      <label class="lab">Image source (optionnel)</label>
      <div class="drop" id="imageDrop">
        <input type="file" id="imageInput" accept="image/*">
        <div class="icon">📷</div>
        <div class="txt">Cliquez ou glissez une image</div>
      </div>
      <div id="imagePreviewWrap"></div>

      <div class="row" style="margin-top:.9rem">
        <div>
          <label class="lab">Taille</label>
          <select id="sizeSelect">
            <option value="1K">1K</option>
            <option value="2K" selected>2K</option>
            <option value="3K">3K</option>
            <option value="4K">4K</option>
          </select>
        </div>
        <div>
          <label class="lab">Ratio</label>
          <select id="ratioSelect">
            <option value="1:1">1:1</option>
            <option value="3:4">3:4</option>
            <option value="4:3">4:3</option>
            <option value="16:9">16:9</option>
            <option value="9:16" selected>9:16</option>
            <option value="2:3">2:3</option>
            <option value="3:2">3:2</option>
            <option value="21:9">21:9</option>
          </select>
        </div>
      </div>

      <div class="row" style="margin-top:.5rem">
        <div>
          <label class="lab">Nombre d'images</label>
          <input type="number" id="countInput" min="1" max="10" value="1" style="min-height:42px">
        </div>
        <div>
          <label class="lab">Concurrence</label>
          <select id="concurrencySelect">
            <option value="1">1 (séquentiel)</option>
            <option value="2" selected>2</option>
            <option value="3">3</option>
            <option value="4">4</option>
            <option value="5">5</option>
            <option value="6">6</option>
            <option value="8">8</option>
            <option value="10">10</option>
          </select>
        </div>
      </div>

      <button class="btn-mega" id="generateBtn">🎬 Générer</button>
      <div class="pipeline-ctrl hidden" id="pipelineCtrl">
        <button class="btn btn-cyan" id="pauseBtn">⏸ Pause</button>
        <button class="btn btn-red" id="stopBtn">⏹ Stop</button>
        <button class="btn btn-gold hidden" id="resumeBtn">▶ Reprendre</button>
      </div>
    </div>

    <div id="stepsBox" class="hidden" style="margin-top:1rem"></div>
    <div id="resultsBox" class="hidden">
      <div class="flex-between" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
        <h2 style="margin:0;border:none;font-size:1.1rem">Résultats</h2>
        <span class="status" id="resultsCount" style="margin:0;padding:.3rem .7rem">0 / 0</span>
      </div>
      <div class="result-grid" id="resultGrid"></div>
    </div>
  </section>

  <!-- ================= ADMIN ================= -->
  <section id="tab-admin" class="hidden">
    <h2>Mes styles</h2>
    <p class="sub" style="font-size:.82rem">Éditez vos prompts de style. <b>✨ IA améliore</b> réécrit le prompt via Agnes. <b>+ Nouveau style</b> ajoute un style personnalisé.</p>
    <button class="btn btn-gold" id="addStyleBtn" style="margin-bottom:1rem">+ Nouveau style</button>
    <div id="styleEditors"></div>
  </section>

  <!-- ================= HISTORIQUE ================= -->
  <section id="tab-history" class="hidden">
    <h2>Ma bibliothèque</h2>
    <p class="sub" style="font-size:.82rem">Toutes vos générations. Les images ne sont téléchargées qu'à la demande pour ne pas surcharger la page.</p>
    <div class="gallery-head">
      <button class="btn btn-gold" id="loadAllHistory">👁 Tout charger</button>
      <span class="hint" id="historyHint"></span>
    </div>
    <div id="historyBox"></div>
  </section>
</div>

<!-- ================= PANNEAU CLÉS API ================= -->
<div id="apiPanel">
  <div id="apiHead">
    <span class="lbl">🔑 AGNES API</span>
    <span style="font-size:.7rem;color:var(--ink2);flex:1" id="apiSummary">Cliquez pour configurer</span>
    <span style="font:700 .7rem ui-monospace;color:var(--gold)" id="apiChevron">▲</span>
  </div>
  <div id="apiBody">
    <p style="font-size:.75rem;color:var(--ink2);margin-bottom:.5rem">Clés Agnes (une par ligne, format <code>sk-…</code>). Stockées dans votre navigateur.</p>
    <textarea id="apiInput" placeholder="sk-xxx&#10;sk-yyy" style="min-height:90px;font-family:ui-monospace,monospace;font-size:12px"></textarea>
    <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap">
      <button class="btn btn-gold" id="apiValidate">✓ Valider & tester</button>
      <button class="btn btn-slate" id="apiClear">Effacer</button>
    </div>
    <div id="apiStatus" class="status">Aucune clé configurée</div>
  </div>
</div>

<div id="toast"></div>

<script>
'use strict';

const CFG = {
  ajaxUrl: '<?= $basePath ?: "." ?>/<?= basename(__FILE__) ?>',
  lsKeys:  'artforge_keys_v2'
};

const $  = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const sleep = ms => new Promise(r => setTimeout(r, ms));

let apiKeys = [];
let STYLES = [];
let currentImage = null;
let LOGGED = <?= $isLogged ? 'true' : 'false' ?>;
let CURRENT_USER = <?= json_encode($username) ?>;

/* ---------- Pipeline state ---------- */
const PIPE = {
  running: false,
  paused: false,
  stopped: false,
  total: 0,
  done: 0,
  failed: 0,
  active: 0,
  abort: false,
};

/* ============================================================
   UTILS
============================================================ */
function toast(msg, type='') {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'on ' + type;
  clearTimeout(t._tid);
  t._tid = setTimeout(() => t.className = type, 3500);
}

function compressImage(dataUrl, maxSide=1400) {
  return new Promise(res => {
    const img = new Image();
    img.onload = () => {
      let {width:w, height:h} = img;
      const s = Math.min(1, maxSide / Math.max(w,h));
      w = Math.round(w*s); h = Math.round(h*s);
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      c.getContext('2d').drawImage(img, 0, 0, w, h);
      res(c.toDataURL('image/jpeg', 0.85));
    };
    img.onerror = () => res(dataUrl);
    img.src = dataUrl;
  });
}

/* ============================================================
   BACKEND
============================================================ */
const Backend = {
  async call(action, params = {}, method = 'POST') {
    const url = CFG.ajaxUrl + '?action=' + encodeURIComponent(action);
    const opts = method === 'GET'
      ? { method: 'GET', credentials: 'same-origin' }
      : { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(params), credentials: 'same-origin' };

    let res;
    try { res = await fetch(url, opts); }
    catch (e) { throw new Error('Réseau : ' + e.message); }

    if (res.status === 401) { location.reload(); throw new Error('Session expirée'); }

    const txt = await res.text();
    let data = {};
    try { data = JSON.parse(txt); }
    catch (e) {
      console.error('Non-JSON (HTTP ' + res.status + '):', txt.slice(0, 400));
      throw new Error('Erreur serveur HTTP ' + res.status);
    }
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
  },

  login(u, p)            { return this.call('login', {username:u, pin:p}); },
  logout()               { return this.call('logout', {}); },
  me()                   { return this.call('me', {}, 'GET'); },
  getStyles()            { return this.call('get_styles', {}, 'GET'); },
  saveStyle(id,name,prompt) { return this.call('save_style', {id,name,prompt}); },
  deleteStyle(id)        { return this.call('delete_style', {id}); },
  improveStyle(prompt,keys) { return this.call('improve_style', {prompt, keys}); },
  analyze(image, keys)   { return this.call('analyze', {image, keys}); },
  generatePrompt(system,user,keys,max=500,temp=0.75) {
    return this.call('generate_prompt', {system, user, keys, max_tokens:max, temperature:temp});
  },
  generateImage(prompt, keys, label='img', size='2K', ratio='9:16') {
    return this.call('generate_image', {prompt, keys, label, size, ratio});
  },
  logGeneration(d)       { return this.call('log_generation', d); },
  history()              { return this.call('history', {}, 'GET'); },
  testKeys(keys)         { return this.call('test_keys', {keys}); }
};

/* ============================================================
   AUTH
============================================================ */
async function doLogin() {
  const u = $('loginUser').value.trim();
  const p = $('loginPin').value.trim();
  const status = $('loginStatus');
  if (!u || !/^\d{4}$/.test(p)) {
    status.className = 'status err';
    status.textContent = 'Pseudo + PIN 4 chiffres requis';
    return;
  }
  const btn = $('loginBtn');
  btn.disabled = true; btn.textContent = '⏳ Connexion…';
  status.className = 'status'; status.textContent = 'Connexion…';
  try {
    const r = await Backend.login(u, p);
    CURRENT_USER = r.user;
    LOGGED = true;
    $('loginBox').classList.add('hidden');
    $('main').classList.remove('hidden');
    $('logoutBtn').classList.remove('hidden');
    $('globalStat').innerHTML = 'Connecté : <b>' + esc(CURRENT_USER) + '</b>';
    await bootApp();
    toast('Bienvenue ' + CURRENT_USER, 'ok');
  } catch (e) {
    status.className = 'status err';
    status.textContent = '❌ ' + e.message;
  } finally {
    btn.disabled = false; btn.textContent = 'Se connecter / Créer un compte';
  }
}

async function doLogout() {
  try { await Backend.logout(); } catch(e) {}
  location.reload();
}

/* ============================================================
   API KEYS
============================================================ */
function updateApiTag() {
  const tag = $('modeTag');
  const stat = $('globalStat');
  if (apiKeys.length) {
    tag.textContent = 'LIVE · ' + apiKeys.length;
    tag.className = 'mode live';
    stat.innerHTML = '<b>' + apiKeys.length + '</b> clé(s) prête(s)';
    $('apiSummary').textContent = apiKeys.length + ' clé(s)';
  } else {
    tag.textContent = 'NON CONFIGURÉ';
    tag.className = 'mode';
    stat.textContent = 'Ajoutez vos clés API pour commencer';
    $('apiSummary').textContent = 'Cliquez pour configurer';
  }
}

async function validateKeys() {
  const raw = $('apiInput').value.split('\n').map(l => l.trim()).filter(l => l.startsWith('sk-'));
  const status = $('apiStatus');
  if (!raw.length) { status.className='status err'; status.textContent='Aucune clé valide'; return; }
  status.className = 'status'; status.textContent = '⏳ Test de ' + raw.length + ' clé(s)…';
  try {
    const r = await Backend.testKeys(raw);
    const valid = (r.results || []).filter(x => x.ok).map(x => x.key);
    if (!valid.length) {
      status.className = 'status err'; status.textContent = '❌ Aucune clé acceptée';
      apiKeys = [];
    } else {
      apiKeys = valid;
      try { localStorage.setItem(CFG.lsKeys, JSON.stringify(valid)); } catch(e){}
      status.className = 'status ok';
      status.textContent = '✓ ' + valid.length + ' clé(s) validée(s)';
      toast(valid.length + ' clé(s) validée(s)', 'ok');
    }
    updateApiTag();
  } catch (e) {
    status.className = 'status err'; status.textContent = '❌ ' + e.message;
  }
}

/* ============================================================
   STYLES
============================================================ */
async function loadStyles() {
  try {
    const r = await Backend.getStyles();
    STYLES = r.styles || [];
    $('styleSelect').innerHTML = STYLES.map(s =>
      `<option value="${esc(s.slug)}">${esc(s.name)}</option>`
    ).join('');
    renderStyleEditors();
  } catch (e) {
    toast('Chargement styles : ' + e.message, 'err');
  }
}

function renderStyleEditors() {
  const box = $('styleEditors');
  box.innerHTML = STYLES.map(s => `
    <div class="style-editor" data-id="${s.id}">
      <h4>
        <input type="text" class="style-name" value="${esc(s.name)}" style="min-height:0;padding:.4rem .6rem;font-size:.9rem;font-weight:600">
        <span class="slug">${esc(s.slug)}</span>
        <button class="btn-mini btn-red" data-del style="margin-left:auto">🗑</button>
      </h4>
      <textarea class="style-prompt">${esc(s.prompt)}</textarea>
      <div class="actions">
        <button class="btn-mini" data-save>💾 Sauvegarder</button>
        <button class="btn-mini" data-improve>✨ IA améliore</button>
        <span class="status" data-status style="margin:0;padding:.3rem .6rem;font-size:.68rem;display:none"></span>
      </div>
    </div>
  `).join('');

  box.querySelectorAll('.style-editor').forEach(el => {
    const id = parseInt(el.dataset.id, 10);
    const nameEl = el.querySelector('.style-name');
    const promptEl = el.querySelector('.style-prompt');
    const statusEl = el.querySelector('[data-status]');

    el.querySelector('[data-save]').addEventListener('click', async () => {
      try {
        await Backend.saveStyle(id, nameEl.value.trim(), promptEl.value.trim());
        toast('Style sauvegardé', 'ok');
        await loadStyles();
      } catch(e) { toast('Sauvegarde : ' + e.message, 'err'); }
    });

    el.querySelector('[data-improve]').addEventListener('click', async (ev) => {
      if (!apiKeys.length) { toast('Configurez vos clés API', 'err'); return; }
      const btn = ev.currentTarget;
      btn.disabled = true; btn.textContent = '⏳…';
      try {
        const r = await Backend.improveStyle(promptEl.value.trim(), apiKeys);
        promptEl.value = r.prompt;
        statusEl.style.display = 'inline-block';
        statusEl.className = 'status warn';
        statusEl.textContent = '✨ Amélioré — Sauvegarder';
      } catch(e) { toast('IA : ' + e.message, 'err'); }
      finally { btn.disabled = false; btn.textContent = '✨ IA améliore'; }
    });

    el.querySelector('[data-del]').addEventListener('click', async () => {
      if (!confirm('Supprimer ce style ?')) return;
      try {
        await Backend.deleteStyle(id);
        toast('Style supprimé', 'ok');
        await loadStyles();
      } catch(e) { toast('Suppression : ' + e.message, 'err'); }
    });
  });
}

/* ============================================================
   IMAGE UPLOAD
============================================================ */
async function handleImage(file) {
  if (!file || !file.type.startsWith('image/')) return;
  const dataUrl = await new Promise((res, rej) => {
    const r = new FileReader();
    r.onload = e => res(e.target.result);
    r.onerror = rej;
    r.readAsDataURL(file);
  });
  currentImage = await compressImage(dataUrl);
  renderPreview();
}

function renderPreview() {
  const w = $('imagePreviewWrap');
  if (!currentImage) { w.innerHTML = ''; return; }
  w.innerHTML = `<div class="preview"><img src="${currentImage}" alt=""><button class="rm" id="rmImage">✕</button></div>`;
  $('rmImage').addEventListener('click', () => { currentImage = null; renderPreview(); });
}

/* ============================================================
   PIPELINE MULTI-IMAGES (Pause / Stop / Reprendre)
============================================================ */
function addStep(label, icon) {
  const box = $('stepsBox');
  box.classList.remove('hidden');
  const el = document.createElement('div');
  el.className = 'step running';
  el.innerHTML = `<span class="ico">${icon}</span><span class="msg"><span class="spinner"></span> ${esc(label)}</span>`;
  box.appendChild(el);
  return el;
}
function updateStep(el, msg, ok=true) {
  el.className = 'step ' + (ok ? 'done' : 'error');
  el.querySelector('.msg').innerHTML = (ok ? '✓ ' : '❌ ') + esc(msg);
}

function resetPipelineUI() {
  $('stepsBox').innerHTML = '';
  $('stepsBox').classList.add('hidden');
  $('resultsBox').classList.add('hidden');
  $('resultGrid').innerHTML = '';
  $('resultsCount').textContent = '0 / 0';
  $('pipelineCtrl').classList.add('hidden');
  $('pauseBtn').classList.remove('hidden');
  $('resumeBtn').classList.add('hidden');
  $('stopBtn').disabled = false;
  $('pauseBtn').disabled = false;
  PIPE.running = false; PIPE.paused = false; PIPE.stopped = false;
  PIPE.total = 0; PIPE.done = 0; PIPE.failed = 0; PIPE.active = 0; PIPE.abort = false;
}

function updateCount() {
  const el = $('resultsCount');
  let txt = `${PIPE.done} / ${PIPE.total}`;
  if (PIPE.failed) txt += ` · ${PIPE.failed} err.`;
  el.textContent = txt;
}

async function waitIfPaused() {
  while (PIPE.paused && !PIPE.stopped) await sleep(200);
}

/* ---------- Génération d'une image ---------- */
async function generateOne(prompt, size, ratio, label) {
  await waitIfPaused();
  if (PIPE.stopped) throw new Error('Stopped');
  PIPE.active++;
  try {
    const r = await Backend.generateImage(prompt, apiKeys, label, size, ratio);
    return r.url;
  } finally {
    PIPE.active--;
  }
}

/* ---------- Carte image avec prompt éditable + régénération inline ---------- */
function createImageCard(data) {
  const card = document.createElement('div');
  card.className = 'img-card';
  card.innerHTML = `
    <div class="img-wrap">
      <img src="${esc(data.url)}" alt="" loading="lazy" class="new">
    </div>
    <div class="info">
      <div class="meta">${esc(data.style || '')} · #${data.index + 1}</div>
      <textarea class="prompt-edit">${esc(data.prompt)}</textarea>
      <div class="actions">
        <button class="btn-mini" data-copy>📋 Copier</button>
        <button class="btn-mini" data-regen>↻ Régénérer</button>
        <span class="status" data-msg style="display:none;margin:0;padding:.25rem .5rem;font-size:.65rem"></span>
      </div>
    </div>`;

  const ta = card.querySelector('.prompt-edit');
  const msg = card.querySelector('[data-msg]');

  card.querySelector('[data-copy]').addEventListener('click', () => {
    navigator.clipboard.writeText(ta.value)
      .then(() => toast('Prompt copié', 'ok'))
      .catch(() => toast('Copie impossible', 'err'));
  });

  card.querySelector('[data-regen]').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    if (btn.disabled) return;
    btn.disabled = true;
    msg.style.display = 'inline-block';
    msg.className = 'status';
    msg.innerHTML = '<span class="spinner"></span> Régénération…';

    const prompt = ta.value.trim();
    if (!prompt) { toast('Prompt vide', 'err'); btn.disabled = false; return; }
    const size = $('sizeSelect').value;
    const ratio = $('ratioSelect').value;

    try {
      const url = await Backend.generateImage(prompt, apiKeys, 'regen_' + Date.now(), size, ratio);
      const imgWrap = card.querySelector('.img-wrap');
      const oldImgs = imgWrap.querySelectorAll('img');
      oldImgs.forEach(im => { im.classList.remove('new'); im.classList.add('old'); });
      const newImg = document.createElement('img');
      newImg.src = url.url;
      newImg.className = 'new';
      newImg.loading = 'lazy';
      newImg.alt = 'regenerated';
      imgWrap.appendChild(newImg);

      msg.className = 'status ok';
      msg.textContent = '✓ Nouvelle image';
      toast('Image régénérée', 'ok');

      Backend.logGeneration({
        style_slug: data.style,
        user_input: '',
        used_image: 0,
        vision_desc: '',
        final_prompt: prompt,
        image_url: url.url,
        parent_id: data.parentId || null,
      }).catch(() => {});
    } catch (e) {
      msg.className = 'status err';
      msg.textContent = '❌ ' + e.message;
    } finally {
      btn.disabled = false;
    }
  });

  return card;
}

/* ---------- Lancement principal ---------- */
async function generate() {
  const styleSlug = $('styleSelect').value;
  const style = STYLES.find(s => s.slug === styleSlug);
  const userPrompt = $('promptInput').value.trim();
  const hasImage = !!currentImage;
  if (!style) { toast('Style invalide', 'err'); return; }
  if (!apiKeys.length) { toast('Configurez vos clés API', 'err'); return; }
  if (!hasImage && !userPrompt) { toast('Fournissez une image OU un prompt', 'err'); return; }

  const size = $('sizeSelect').value;
  const ratio = $('ratioSelect').value;
  const count = Math.max(1, Math.min(10, parseInt($('countInput').value || '1', 10)));
  const concurrency = Math.max(1, Math.min(10, parseInt($('concurrencySelect').value || '2', 10)));

  resetPipelineUI();
  PIPE.running = true;
  PIPE.total = count;
  $('generateBtn').disabled = true;
  $('generateBtn').textContent = '⏳ Génération…';
  $('pipelineCtrl').classList.remove('hidden');
  $('resultsBox').classList.remove('hidden');
  updateCount();

  let visionDesc = '';
  let finalPrompt = '';

  try {
    /* STEP 1 — VISION */
    if (hasImage) {
      const s = addStep('Analyse visuelle…', '🔍');
      try {
        const r = await Backend.analyze(currentImage, apiKeys);
        visionDesc = r.content;
        updateStep(s, 'Image analysée (' + visionDesc.length + ' car.)');
      } catch(e) {
        updateStep(s, 'Analyse échouée : ' + e.message, false);
        throw e;
      }
    }

    /* STEP 2 — PROMPT */
    const s2 = addStep('Construction du prompt stylisé…', '✍️');
    let userMsg = '';
    if (visionDesc) userMsg += "DESCRIPTION DE L'IMAGE SOURCE :\n" + visionDesc + "\n\n";
    if (userPrompt) userMsg += "INTENTION UTILISATEUR :\n" + userPrompt + "\n\n";
    userMsg += "Transforme cette scène en prompt d'image selon TON STYLE. Retourne UNIQUEMENT le prompt en anglais, sans guillemets ni préambule.";
    try {
      const r = await Backend.generatePrompt(style.prompt, userMsg, apiKeys, 600, 0.75);
      finalPrompt = r.content.trim();
      updateStep(s2, 'Prompt généré (' + finalPrompt.split(/\s+/).length + ' mots)');
    } catch(e) {
      updateStep(s2, 'Prompt échoué : ' + e.message, false);
      throw e;
    }

    /* STEP 3 — N IMAGES avec concurrence */
    const s3 = addStep(`Génération de ${count} image(s)…`, '🎨');
    const grid = $('resultGrid');

    const tasks = Array.from({length: count}, (_, i) => i);
    let cursor = 0;

    const worker = async () => {
      while (true) {
        await waitIfPaused();
        if (PIPE.stopped || PIPE.abort) return;
        const i = cursor++;
        if (i >= tasks.length) return;

        try {
          const url = await generateOne(finalPrompt, size, ratio, 'artforge_' + styleSlug + '_' + i);
          PIPE.done++;
          updateCount();

          const card = createImageCard({
            prompt: finalPrompt,
            url,
            style: styleSlug,
            index: i,
            parentId: null,
          });
          grid.appendChild(card);

          Backend.logGeneration({
            style_slug: styleSlug,
            user_input: userPrompt,
            used_image: hasImage ? 1 : 0,
            vision_desc: visionDesc,
            final_prompt: finalPrompt,
            image_url: url,
            parent_id: null,
          }).catch(() => {});
        } catch(e) {
          PIPE.failed++;
          updateCount();
          const errCard = document.createElement('div');
          errCard.className = 'img-card';
          errCard.innerHTML = `<div class="img-wrap"><div style="padding:1rem;color:var(--red);font-size:.8rem">❌ ${esc(e.message)}</div></div>`;
          grid.appendChild(errCard);
        }
      }
    };

    await Promise.all(Array.from({length: concurrency}, () => worker()));

    updateStep(s3, PIPE.stopped ? 'Arrêté' : 'Terminé', true);
    toast('Génération terminée', 'ok');
  } catch(e) {
    toast('Erreur : ' + e.message, 'err');
  } finally {
    PIPE.running = false;
    $('generateBtn').disabled = false;
    $('generateBtn').textContent = '🎬 Générer';
    $('pauseBtn').classList.add('hidden');
    $('resumeBtn').classList.add('hidden');
    $('stopBtn').disabled = true;
  }
}

/* ---------- Contrôles pause / stop / reprendre ---------- */
function bindPipelineControls() {
  $('pauseBtn').addEventListener('click', () => {
    PIPE.paused = true;
    $('pauseBtn').classList.add('hidden');
    $('resumeBtn').classList.remove('hidden');
    toast('En pause — les tâches en cours continuent', 'info');
  });
  $('resumeBtn').addEventListener('click', () => {
    PIPE.paused = false;
    $('resumeBtn').classList.add('hidden');
    $('pauseBtn').classList.remove('hidden');
    toast('Reprise…', 'info');
  });
  $('stopBtn').addEventListener('click', () => {
    PIPE.stopped = true;
    $('stopBtn').disabled = true;
    toast('Arrêt demandé', 'info');
  });
}

/* ============================================================
   HISTORIQUE (lazy-load : on ne charge les images qu'à la demande)
============================================================ */
async function loadHistory() {
  const box = $('historyBox');
  const hint = $('historyHint');
  box.innerHTML = '<p class="sub">⏳ Chargement…</p>';
  hint.textContent = '';
  try {
    const r = await Backend.history();
    const items = r.items || [];
    if (!items.length) {
      box.innerHTML = '<p class="sub">Aucune génération pour le moment.</p>';
      return;
    }

    /* On affiche UNIQUEMENT les métadonnées + un placeholder. Aucune <img src=…>
       n'est créée tant que l'utilisateur ne clique pas sur "Charger". */
    box.innerHTML = '<div class="result-grid">' + items.map((it, idx) => `
      <div class="img-card history-card" data-idx="${idx}" data-url="${esc(it.image_url)}">
        <div class="img-wrap">
          <div class="img-placeholder" data-ph>
            <div class="ph-icon">🖼️</div>
            <div class="ph-label">${esc(it.style_slug || 'image')}</div>
            <button class="btn btn-cyan" data-load style="min-height:34px;font-size:.72rem;padding:.35rem .8rem">👁 Charger</button>
          </div>
        </div>
        <div class="info">
          <div class="meta">${esc(it.style_slug)} · ${esc(it.created_at)}</div>
          <textarea class="prompt-edit" readonly>${esc(it.final_prompt)}</textarea>
          <div class="actions">
            <button class="btn-mini" data-copy>📋 Copier</button>
            <button class="btn-mini" data-load>👁 Charger</button>
          </div>
        </div>
      </div>
    `).join('') + '</div>';

    hint.textContent = items.length + ' image(s) — cliquez sur 👁 Charger pour afficher';

    const cards = box.querySelectorAll('.history-card');
    const loadCard = (card) => {
      const ph = card.querySelector('[data-ph]');
      if (!ph) return; // déjà chargée
      const url = card.dataset.url;
      if (!url) return;

      const buttons = card.querySelectorAll('[data-load]');
      buttons.forEach(b => { b.disabled = true; });
      ph.innerHTML = '<span class="spinner"></span><div class="ph-label">Chargement…</div>';

      const img = new Image();
      img.onload = () => {
        img.loading = 'lazy';
        img.alt = '';
        img.style.border = '2px solid var(--green)';
        ph.replaceWith(img);
      };
      img.onerror = () => {
        ph.innerHTML = '<div class="ph-icon">⚠️</div><div class="ph-label">Échec du chargement</div>';
        buttons.forEach(b => { b.disabled = false; });
      };
      img.src = url;
    };

    cards.forEach(card => {
      card.querySelectorAll('[data-load]').forEach(btn => {
        btn.addEventListener('click', () => loadCard(card));
      });

      const copyBtn = card.querySelector('[data-copy]');
      if (copyBtn) {
        copyBtn.addEventListener('click', () => {
          const ta = card.querySelector('.prompt-edit');
          if (!ta) return;
          navigator.clipboard.writeText(ta.value)
            .then(() => toast('Prompt copié', 'ok'))
            .catch(() => toast('Copie impossible', 'err'));
        });
      }
    });

    /* Bouton "Tout charger" : charge toutes les cartes une par une */
    $('loadAllHistory').onclick = async () => {
      const remaining = Array.from(cards).filter(c => c.querySelector('[data-ph]'));
      if (!remaining.length) { toast('Toutes les images sont déjà chargées', 'info'); return; }
      toast('Chargement de ' + remaining.length + ' image(s)…', 'info');
      for (const c of remaining) {
        loadCard(c);
        await sleep(120); // petit délai pour ne pas saturer le serveur
      }
    };
  } catch(e) {
    box.innerHTML = '<p class="sub">Erreur : ' + esc(e.message) + '</p>';
  }
}

/* ============================================================
   TABS
============================================================ */
function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  ['create','admin','history'].forEach(id => {
    $('tab-' + id).classList.toggle('hidden', id !== name);
  });
  if (name === 'history') loadHistory();
}

/* ============================================================
   BOOT
============================================================ */
async function bootApp() {
  try {
    const saved = JSON.parse(localStorage.getItem(CFG.lsKeys) || '[]');
    if (saved.length) {
      apiKeys = saved;
      $('apiInput').value = saved.join('\n');
      $('apiStatus').className = 'status ok';
      $('apiStatus').textContent = '✓ ' + saved.length + ' clé(s) restaurée(s)';
    }
  } catch(e){}
  updateApiTag();
  await loadStyles();
  bindPipelineControls();
}

/* ============================================================
   BIND
============================================================ */
function bind() {
  $('loginBtn').addEventListener('click', doLogin);
  $('loginPin').addEventListener('keypress', e => { if (e.key === 'Enter') doLogin(); });
  $('logoutBtn').addEventListener('click', doLogout);

  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => switchTab(t.dataset.tab));
  });

  $('imageInput').addEventListener('change', async (e) => {
    const f = e.target.files[0];
    if (f) await handleImage(f);
    e.target.value = '';
  });
  const drop = $('imageDrop');
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = 'var(--gold)'; }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = ''; }));
  drop.addEventListener('drop', async (e) => {
    const f = e.dataTransfer.files[0];
    if (f) await handleImage(f);
  });

  $('generateBtn').addEventListener('click', generate);

  $('addStyleBtn').addEventListener('click', async () => {
    const name = prompt('Nom du nouveau style :');
    if (!name) return;
    const promptTxt = prompt('Prompt système du style :');
    if (!promptTxt) return;
    try {
      await Backend.saveStyle(0, name.trim(), promptTxt.trim());
      toast('Style ajouté', 'ok');
      await loadStyles();
    } catch(e) { toast('Ajout : ' + e.message, 'err'); }
  });

  $('apiHead').addEventListener('click', () => {
    $('apiBody').classList.toggle('open');
    $('apiChevron').textContent = $('apiBody').classList.contains('open') ? '▼' : '▲';
  });
  $('apiValidate').addEventListener('click', validateKeys);
  $('apiClear').addEventListener('click', () => {
    $('apiInput').value = '';
    apiKeys = [];
    try { localStorage.removeItem(CFG.lsKeys); } catch(e){}
    $('apiStatus').className = 'status';
    $('apiStatus').textContent = 'Aucune clé configurée';
    updateApiTag();
  });

  if (LOGGED) {
    $('loginBox').classList.add('hidden');
    $('main').classList.remove('hidden');
    $('logoutBtn').classList.remove('hidden');
    $('globalStat').innerHTML = 'Connecté : <b>' + esc(CURRENT_USER) + '</b>';
    bootApp();
  } else {
    $('main').classList.add('hidden');
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
else bind();
</script>
</body>
</html>
