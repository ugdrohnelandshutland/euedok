<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Actor;
use Euedok\Auth;
use Euedok\Reports;
use Euedok\Settings;

if (!Settings::isInstalled()) {
    redirect('setup');
}

$action = $_GET['action'] ?? '';

/* ---------- Anmelden / Abmelden ---------- */
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $err = Auth::attempt(post_str('username'), (string) ($_POST['password'] ?? ''), post_bool('remember'));
    if ($err !== null) {
        flash($err, 'err');
        redirect('login');
    }
    $target = $_SESSION['after_login'] ?? '';
    unset($_SESSION['after_login']);
    // nur eigene Pfade: genau ein führender „/“, kein „//“ oder „\“ (sonst Weiterleitung auf fremde Seiten)
    if (is_string($target) && str_starts_with($target, base_url()) && preg_match('#^/(?![/\\\\])[^\\\\\s]*$#', $target)
        && !str_contains($target, '//') && !str_contains($target, 'action=') && !str_contains($target, '/actions/')) {
        header('Location: ' . $target);
        exit;
    }
    redirect('');
}
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    Auth::logout();
    redirect('login');
}

if (Auth::user() === null) {
    // z. B. /reports ohne Anmeldung: Ziel merken und auf /login – nach der Anmeldung geht es dorthin zurück
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!in_array($path, [base_url(''), base_url('login'), base_url('index.php')], true)) {
        $_SESSION['after_login'] = (string) $_SERVER['REQUEST_URI'];
        redirect('login');
    }
    render('partials/head', ['title' => 'Anmelden', 'plain' => true]);
    render('login');
    render('partials/foot');
    exit;
}
$user = Auth::requireLogin();

/* ---------- Neuer Bericht ---------- */
if ($action === 'new' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $report = Reports::create(post_str('type'), Actor::fromUser($user));
    redirect('reports/' . rawurlencode($report['report_number']) . '?new=1');
}
if ($action === 'discard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $n = post_str('n');
    if (Reports::discardDraft($n)) {
        \Euedok\Log::audit('reports', 'Entwurf gelöscht: ' . $n);
        flash('Entwurf ' . $n . ' gelöscht.');
    } else {
        flash('Entwurf ' . $n . ' konnte nicht gelöscht werden (nicht gefunden oder bereits abgeschlossen).', 'err');
    }
    // Zurück zur aufrufenden Seite (nur interne Ziele)
    $back = post_str('back');
    redirect(preg_match('#^(reports(\?[\w=&%.\-]*)?)?$#', $back) ? $back : '');
}
if ($action === 'resend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $n = post_str('n');
    $res = Reports::sendMail($n, (int) (Reports::meta($n)['correction'] ?? 0), Actor::fromUser($user));
    flash(match ($res['status']) {
        'sent' => 'Bericht ' . $n . ' versendet an ' . implode(', ', $res['to']) . '.',
        'off' => 'Mailversand ist ausgeschaltet.',
        default => 'Versand fehlgeschlagen: ' . ($res['error'] ?? ''),
    }, $res['status'] === 'sent' ? 'ok' : 'err');
    redirect('reports');
}

/* ---------- Übersicht ---------- */
if (($_GET['view'] ?? '') === 'reports') {
    $filter = [
        'year' => (int) ($_GET['year'] ?? date('Y')),
        'type' => is_string($_GET['type'] ?? null) ? $_GET['type'] : '',
        'status' => is_string($_GET['status'] ?? null) ? $_GET['status'] : '',
        'q' => is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '',
    ];
    render('partials/head', ['title' => 'Berichte', 'nav' => 'reports']);
    render('reports', ['filter' => $filter, 'rows' => Reports::list($filter), 'years' => Reports::years()]);
    render('partials/foot', ['nav' => 'reports']);
    exit;
}

/* ---------- Startseite ---------- */
$drafts = array_values(array_filter(
    Reports::list(['status' => 'draft']),
    static fn ($r) => ($r['created_by_user'] ?? '') === $user['username'] || !empty($r['qr_access_id'])
));
$recent = array_slice(Reports::list(['status' => 'closed']), 0, 6);

render('partials/head', ['title' => 'Start', 'nav' => 'home']);
render('home', ['drafts' => $drafts, 'recent' => $recent, 'user' => $user]);
render('partials/foot', ['nav' => 'home']);
