<?php
declare(strict_types=1);

function config(string $key): mixed
{
    return $GLOBALS['config'][$key] ?? null;
}

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path): string
{
    return rtrim((string)config('base_url'), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get_int(string $key): int
{
    return max(0, (int)($_GET[$key] ?? 0));
}

// ---------- Messages flash ----------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------- CSRF ----------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Jeton de sécurité invalide. Rechargez la page et recommencez.');
    }
}

// ---------- Divers ----------

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function fmt_date(?string $d, bool $withTime = true): string
{
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '—';
}

/** Mot de passe aléatoire lisible (sans caractères ambigus). */
function random_password(int $length = 12): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%*?';
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function password_is_strong(string $p): bool
{
    return strlen($p) >= 10
        && preg_match('/[a-z]/', $p)
        && preg_match('/[A-Z]/', $p)
        && preg_match('/[0-9]/', $p);
}

/** Pagination simple : renvoie [limit, offset, page]. */
function paginate(int $perPage = 25): array
{
    $page = max(1, get_int('page'));
    return [$perPage, ($page - 1) * $perPage, $page];
}

/** Query string courante avec des paramètres remplacés. */
function qs(array $replace): string
{
    $q = array_merge($_GET, $replace);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return $q ? '?' . http_build_query($q) : '?';
}
