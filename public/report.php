<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Auth;
use Euedok\Divera;
use Euedok\FormConfig;
use Euedok\Reports;

$user = Auth::requireLogin();
$number = is_string($_GET['n'] ?? null) ? $_GET['n'] : '';
$report = Reports::load($number);
$meta = Reports::meta($number);
if (!$report || !$meta) {
    http_response_code(404);
    flash('Bericht ' . $number . ' nicht gefunden.', 'err');
    redirect('index.php');
}

$edit = $report['status'] === 'draft' || isset($_GET['edit']);

if (!$edit) {
    render('partials/head', ['title' => $number, 'nav' => 'reports']);
    render('report-view', ['r' => $report, 'meta' => $meta]);
    render('partials/foot', ['nav' => 'reports']);
    exit;
}

$cfg = FormConfig::build($report, $meta, [
    'mode' => 'user',
    'correction' => $report['status'] === 'closed',
    'ask_divera' => isset($_GET['new']) && $report['status'] === 'draft' && empty($report['divera']),
    'divera_lookup' => Divera::lookupAllowed($report['type']['id'], false),
    'home' => base_url('index.php'),
    'back' => base_url($report['status'] === 'closed' ? 'report.php?n=' . rawurlencode($number) : 'index.php'),
]);

render('partials/head', ['title' => $number, 'bodyClass' => 'report', 'plain' => true, 'scripts' => ['js/report.js']]);
render('report-form', ['cfg' => $cfg, 'r' => $report]);
render('partials/foot', ['noBottomNav' => true]);
