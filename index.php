<?php
// ---------------------------------------------------------------------------
// Serves the React app (frontend/dist/index.html) for every client-side route
// (see .htaccess). The build uses relative asset URLs; this injects the path
// the app is actually served from as <base href>, so the same build works at
// a domain root or in any subfolder without rebuilding.
// ---------------------------------------------------------------------------

$indexFile = __DIR__ . '/frontend/dist/index.html';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!is_file($indexFile)) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>CreatePosts</title>'
       . '<p style="font:16px system-ui,sans-serif;margin:3rem auto;max-width:36rem">'
       . 'The app hasn\'t been built yet. Run <code>npm run build</code> in the repository folder on the server.</p>';
    exit;
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$html = (string) file_get_contents($indexFile);
echo preg_replace('/<head(\s[^>]*)?>/i', '$0<base href="' . htmlspecialchars($base, ENT_QUOTES) . '">', $html, 1);
