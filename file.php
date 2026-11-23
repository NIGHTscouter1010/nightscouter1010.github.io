<?php
/**
 * Gestor de archivos PHP de un solo fichero.
 * Subir, crear carpetas/archivos, renombrar, editar, descargar y borrar.
 * Trabaja dentro de la carpeta donde lo cuelgues (y sus subcarpetas).
 */
session_start();
define('PASSWORD', 'ftpserver');
$ROOT = realpath(__DIR__);
$SELF = basename(__FILE__);

/* ---------- utilidades ---------- */
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function safe_path($rel) {
    global $ROOT;
    $rel = str_replace("\0", '', $rel);
    $p = realpath($ROOT . DIRECTORY_SEPARATOR . $rel);
    if ($p === false) return false;
    if ($p !== $ROOT && strpos($p, $ROOT . DIRECTORY_SEPARATOR) !== 0) return false;
    return $p;
}
function rel_of($abs) {
    global $ROOT;
    return ltrim(str_replace('\\', '/', substr($abs, strlen($ROOT))), '/');
}
function valid_name($n) {
    return $n !== '' && $n !== '.' && $n !== '..' && !preg_match('#[/\\\\\0]#', $n);
}
function rrmdir($d) {
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . DIRECTORY_SEPARATOR . $f;
        (is_dir($p) && !is_link($p)) ? rrmdir($p) : unlink($p);
    }
    return rmdir($d);
}
function fmt_size($b) {
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}
function redirect($rel, $msg = '', $err = false) {
    global $SELF;
    $_SESSION['flash'] = [$msg, $err];
    header('Location: ' . $SELF . '?d=' . rawurlencode($rel));
    exit;
}

/* ---------- login / logout ---------- */
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $SELF);
    exit;
}
if (!isset($_SESSION['auth'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals(PASSWORD, (string)$_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: ' . $SELF);
            exit;
        }
        sleep(1);
        $error = 'Contraseña incorrecta';
    }
    ?><!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso</title>
<style>body{font-family:system-ui,sans-serif;background:#10141c;color:#e6e9ef;display:grid;place-items:center;height:100vh;margin:0}
form{background:#1b2230;padding:28px;border-radius:12px;width:290px;box-shadow:0 8px 30px #0006}
h2{margin:0 0 16px}input{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #334;background:#0f1420;color:#fff;margin-bottom:12px}
button{width:100%;padding:10px;border:0;border-radius:8px;background:#3b82f6;color:#fff;font-size:15px;cursor:pointer}
.e{color:#ff7b7b;margin-bottom:10px;font-size:14px}</style></head><body>
<form method="post"><h2>🔒 Gestor de archivos</h2>
<?php if ($error) echo '<div class="e">' . h($error) . '</div>'; ?>
<input type="password" name="password" placeholder="Contraseña" autofocus required>
<button>Entrar</button></form></body></html><?php
    exit;
}

/* ---------- directorio actual ---------- */
$cur = safe_path($_GET['d'] ?? '');
if ($cur === false || !is_dir($cur)) $cur = $ROOT;
$rel = rel_of($cur);

/* ---------- descarga ---------- */
if (isset($_GET['dl'])) {
    $f = safe_path($_GET['dl']);
    if ($f && is_file($f)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($f)) . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
    redirect($rel, 'Archivo no encontrado', true);
}

/* ---------- acciones POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        redirect($rel, 'Token de seguridad inválido', true);
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'upload' && !empty($_FILES['files'])) {
        $ok = 0; $bad = 0;
        foreach ($_FILES['files']['name'] as $i => $name) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) { $bad++; continue; }
            $name = basename($name);
            if (!valid_name($name)) { $bad++; continue; }
            move_uploaded_file($_FILES['files']['tmp_name'][$i], $cur . DIRECTORY_SEPARATOR . $name) ? $ok++ : $bad++;
        }
        redirect($rel, "Subidos: $ok" . ($bad ? " · con error: $bad" : ''), $bad > 0);
    }

    if ($act === 'mkdir') {
        $n = trim($_POST['name'] ?? '');
        if (!valid_name($n)) redirect($rel, 'Nombre no válido', true);
        $t = $cur . DIRECTORY_SEPARATOR . $n;
        if (file_exists($t)) redirect($rel, 'Ya existe', true);
        redirect($rel, @mkdir($t, 0755) ? 'Carpeta creada' : 'No se pudo crear la carpeta', !is_dir($t));
    }

    if ($act === 'newfile') {
        $n = trim($_POST['name'] ?? '');
        if (!valid_name($n)) redirect($rel, 'Nombre no válido', true);
        $t = $cur . DIRECTORY_SEPARATOR . $n;
        if (file_exists($t)) redirect($rel, 'Ya existe', true);
        redirect($rel, @file_put_contents($t, '') !== false ? 'Archivo creado' : 'No se pudo crear', !is_file($t));
    }

    if ($act === 'delete') {
        $t = safe_path($_POST['target'] ?? '');
        if (!$t || $t === $ROOT) redirect($rel, 'Ruta no válida', true);
        if ($t === __FILE__) redirect($rel, 'No puedes borrar el propio gestor desde aquí', true);
        $ok = (is_dir($t) && !is_link($t)) ? rrmdir($t) : @unlink($t);
        redirect($rel, $ok ? 'Borrado' : 'No se pudo borrar', !$ok);
    }

    if ($act === 'rename') {
        $t = safe_path($_POST['target'] ?? '');
        $n = trim($_POST['name'] ?? '');
        if (!$t || $t === $ROOT || !valid_name($n)) redirect($rel, 'Datos no válidos', true);
        $dest = dirname($t) . DIRECTORY_SEPARATOR . $n;
        if (file_exists($dest)) redirect($rel, 'Ya existe ese nombre', true);
        $ok = @rename($t, $dest);
        redirect($rel, $ok ? 'Renombrado' : 'No se pudo renombrar', !$ok);
    }

    if ($act === 'save') {
        $t = safe_path($_POST['target'] ?? '');
        if (!$t || !is_file($t)) redirect($rel, 'Archivo no válido', true);
        $ok = @file_put_contents($t, str_replace("\r\n", "\n", $_POST['content'] ?? '')) !== false;
        redirect(rel_of(dirname($t)), $ok ? 'Guardado' : 'No se pudo guardar', !$ok);
    }
    redirect($rel);
}

/* ---------- editor ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = $_SESSION['csrf'];
$editing = null;
if (isset($_GET['edit'])) {
    $f = safe_path($_GET['edit']);
    if ($f && is_file($f) && filesize($f) < 2 * 1024 * 1024) $editing = $f;
}

/* ---------- listado ---------- */
$items = [];
foreach (scandir($cur) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $cur . DIRECTORY_SEPARATOR . $f;
    $items[] = ['name' => $f, 'dir' => is_dir($p), 'size' => is_file($p) ? filesize($p) : 0, 'time' => filemtime($p)];
}
usort($items, function ($a, $b) {
    return $a['dir'] === $b['dir'] ? strcasecmp($a['name'], $b['name']) : ($a['dir'] ? -1 : 1);
});
$crumbs = $rel === '' ? [] : explode('/', $rel);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestor de archivos</title>
<style>
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#10141c;color:#e6e9ef;margin:0;padding:18px}
a{color:#7db4ff;text-decoration:none}a:hover{text-decoration:underline}
.top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px}
h1{font-size:20px;margin:0}
.card{background:#1b2230;border-radius:12px;padding:14px;margin-bottom:14px}
.tools{display:flex;gap:10px;flex-wrap:wrap}
.tools form{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
input[type=text],input[type=file],textarea{background:#0f1420;color:#fff;border:1px solid #334;border-radius:8px;padding:8px}
button{background:#3b82f6;color:#fff;border:0;border-radius:8px;padding:8px 12px;cursor:pointer}
button.r{background:#dc3c3c}button.g{background:#445}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:8px;border-bottom:1px solid #2a3345;font-size:14px}
td.a{white-space:nowrap}td.a form{display:inline}
.msg{padding:10px;border-radius:8px;margin-bottom:12px;background:#1f6f43}.msg.err{background:#8f2b2b}
textarea{width:100%;height:60vh;font-family:ui-monospace,monospace;font-size:13px}
.crumbs{font-size:14px;margin-bottom:10px}
</style></head><body>
<div class="top"><h1>📁 Gestor de archivos</h1><a href="?logout=1">Cerrar sesión</a></div>

<?php if ($flash && $flash[0]) echo '<div class="msg' . ($flash[1] ? ' err' : '') . '">' . h($flash[0]) . '</div>'; ?>

<?php if ($editing): ?>
<div class="card">
  <p>Editando: <b><?= h(rel_of($editing)) ?></b></p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="target" value="<?= h(rel_of($editing)) ?>">
    <textarea name="content"><?= h(file_get_contents($editing)) ?></textarea><br><br>
    <button>💾 Guardar</button>
    <a href="?d=<?= rawurlencode(rel_of(dirname($editing))) ?>"><button type="button" class="g">Volver</button></a>
  </form>
</div>
<?php else: ?>

<div class="crumbs">
  <a href="?d=">🏠 raíz</a>
  <?php $acc = ''; foreach ($crumbs as $c) { $acc .= ($acc === '' ? '' : '/') . $c;
      echo ' / <a href="?d=' . rawurlencode($acc) . '">' . h($c) . '</a>'; } ?>
</div>

<div class="card tools">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="upload">
    <input type="file" name="files[]" multiple required>
    <button>⬆ Subir</button>
  </form>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="mkdir">
    <input type="text" name="name" placeholder="Nueva carpeta" required>
    <button>📁 Crear</button>
  </form>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="newfile">
    <input type="text" name="name" placeholder="Nuevo archivo" required>
    <button>📄 Crear</button>
  </form>
</div>

<div class="card">
<table>
  <tr><th>Nombre</th><th>Tamaño</th><th>Modificado</th><th>Acciones</th></tr>
  <?php if ($rel !== ''): ?>
  <tr><td colspan="4"><a href="?d=<?= rawurlencode(rel_of(dirname($cur))) ?>">⬆ ..</a></td></tr>
  <?php endif; ?>
  <?php foreach ($items as $it):
      $r = ($rel === '' ? '' : $rel . '/') . $it['name']; ?>
  <tr>
    <td><?php if ($it['dir']): ?>📁 <a href="?d=<?= rawurlencode($r) ?>"><?= h($it['name']) ?></a>
        <?php else: ?>📄 <?= h($it['name']) ?><?php endif; ?></td>
    <td><?= $it['dir'] ? '—' : fmt_size($it['size']) ?></td>
    <td><?= date('Y-m-d H:i', $it['time']) ?></td>
    <td class="a">
      <?php if (!$it['dir']): ?>
        <a href="?dl=<?= rawurlencode($r) ?>">Descargar</a> ·
        <a href="?edit=<?= rawurlencode($r) ?>">Editar</a> ·
      <?php endif; ?>
      <form method="post" onsubmit="var n=prompt('Nuevo nombre:',<?= h(json_encode($it['name'])) ?>);if(!n)return false;this.name.value=n;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="target" value="<?= h($r) ?>">
        <input type="hidden" name="name" value="">
        <button class="g">Renombrar</button>
      </form>
      <form method="post" onsubmit="return confirm('¿Borrar «<?= h(addslashes($it['name'])) ?>»?<?= $it['dir'] ? ' Se borrará todo su contenido.' : '' ?>')">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="target" value="<?= h($r) ?>">
        <button class="r">Borrar</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="4">Carpeta vacía</td></tr><?php endif; ?>
</table>
</div>
<?php endif; ?>
</body></html>
