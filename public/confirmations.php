<?php
declare(strict_types=1);

/*
 * Einsatzbestätigungen eines abgeschlossenen Berichts versenden.
 * Recht wie „Erneut senden“ (/actions/resend): jeder angemeldete Zugang; QR-Schnellerfassung nicht.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Actor;
use Euedok\Auth;
use Euedok\Confirmations;
use Euedok\Reports;

$user = Auth::requireLogin();
$number = is_string($_GET['n'] ?? null) ? $_GET['n'] : '';
$r = Reports::load($number);
$meta = Reports::meta($number);
if (!$r || !$meta) {
    http_response_code(404);
    flash('Bericht ' . $number . ' nicht gefunden.', 'err');
    redirect('reports');
}
// Bestätigungen vorerst nur für Einsätze; für Übungen später freischalten.
if ($r['type']['id'] !== 'incident') {
    http_response_code(404);
    flash('Bestätigungen gibt es nur für Einsatzberichte.', 'err');
    redirect('reports/' . rawurlencode($number));
}
$back = 'reports/' . rawurlencode($number);
if ($r['status'] !== 'closed') {
    flash('Bestätigungen gibt es erst für abgeschlossene Berichte.', 'err');
    redirect($back);
}
$self = $back . '/confirmations';
$people = Confirmations::people($r);
$values = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$rows, $errors, $values] = Confirmations::validate($people, is_array($_POST['p'] ?? null) ? $_POST['p'] : []);
    if (($notReady = Confirmations::notReady()) !== null) {
        $errors[] = $notReady;
    } elseif (!$rows && !$errors) {
        $errors[] = 'Bitte mindestens eine Person auswählen.';
    }
    if (!$errors) {
        $_SESSION['confirm_results'] = ['n' => $number, 'items' => Confirmations::send($number, $rows, Actor::fromUser($user))];
        redirect($self);
    }
} elseif (isset($_GET['e'])) {
    // „Erneut senden“: nur diese Person, Werte aus dem Protokolleintrag
    $entry = Confirmations::entries($r, $meta)[(int) $_GET['e']] ?? null;
    if ($entry && isset($people[$entry['person_id']])) {
        $values[$entry['person_id']] = ['sel' => true, 'email' => (string) $entry['email'], 'from' => (string) $entry['from'], 'to' => (string) $entry['to']];
    }
}

$results = null;
if (($_SESSION['confirm_results']['n'] ?? null) === $number) {
    $results = $_SESSION['confirm_results']['items'];
    unset($_SESSION['confirm_results']);
}

render('partials/head', ['title' => Confirmations::title($r['type']) . 'en ' . $number, 'nav' => 'reports']);
render('confirmations', ['r' => $r, 'people' => $people, 'values' => $values, 'errors' => $errors, 'results' => $results, 'notReady' => Confirmations::notReady()]);
render('partials/foot', ['nav' => 'reports']);
