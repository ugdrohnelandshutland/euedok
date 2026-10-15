<?php
declare(strict_types=1);

namespace Euedok;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/** Mailversand per SMTP (PHPMailer). Transport „Datei“ schreibt Mails nach data/mail-outbox/ (zum Testen). */
final class Mailer
{
    public static function enabled(): bool
    {
        $m = Settings::mail();
        return !empty($m['enabled']) && !empty($m['recipients']);
    }

    private static function make(): PHPMailer
    {
        $m = Settings::mail();
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->XMailer = 'EUEDOK';
        if ($m['transport'] === 'smtp') {
            $mail->isSMTP();
            $mail->Host = (string) $m['smtp_host'];
            $mail->Port = (int) $m['smtp_port'];
            $mail->Timeout = 15;
            $mail->SMTPSecure = match ($m['smtp_security']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->SMTPAutoTLS = $m['smtp_security'] !== 'none';
            if ($m['smtp_user'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = (string) $m['smtp_user'];
                $mail->Password = (string) Crypto::decrypt($m['smtp_password_enc']);
            }
        }
        $from = $m['from_address'] !== '' ? $m['from_address'] : ($m['smtp_user'] !== '' ? $m['smtp_user'] : 'euedok@localhost');
        $mail->setFrom($from, (string) ($m['from_name'] ?: 'EÜDOK'));
        return $mail;
    }

    /** @return array{success: bool, to: array, error?: string} */
    private static function deliver(PHPMailer $mail, array $to): array
    {
        $m = Settings::mail();
        $ctx = [
            'to' => $to,
            'subject' => $mail->Subject,
            'transport' => $m['transport'],
            'host' => $m['transport'] === 'smtp' ? $m['smtp_host'] . ':' . $m['smtp_port'] . ' (' . $m['smtp_security'] . ')' : null,
            'attachments' => array_map(static fn ($a) => $a[2], $mail->getAttachments()),
        ];
        // SMTP-Dialog mitschneiden (ohne Anmeldedaten), nur bei Fehlern ins Protokoll
        $transcript = [];
        if ($m['transport'] === 'smtp') {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = static function (string $line) use (&$transcript): void {
                $line = trim($line);
                if (preg_match('/AUTH|^CLIENT -> SERVER: [A-Za-z0-9+\/=]{8,}$/', $line)) {
                    $line = preg_replace('/(CLIENT -> SERVER: ).*/', '$1***', $line);
                }
                $transcript[] = mb_substr($line, 0, 300);
            };
        }
        $t0 = microtime(true);
        try {
            foreach ($to as $addr) {
                $mail->addAddress($addr);
            }
            if ($m['transport'] === 'file') {
                $mail->preSend();
                $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
                Storage::writeRaw('mail-outbox/' . $name, $mail->getSentMIMEMessage());
                $ctx['file'] = $name;
            } else {
                $mail->send();
            }
            Log::info('mail', 'sent', 'Mail versendet', $ctx + ['ms' => (int) round((microtime(true) - $t0) * 1000)]);
            return ['success' => true, 'to' => $to];
        } catch (MailException $e) {
            $err = $mail->ErrorInfo ?: $e->getMessage();
            Log::error('mail', 'failed', $err, $ctx + [
                'ms' => (int) round((microtime(true) - $t0) * 1000),
                'smtp_transcript' => array_slice($transcript, -40),
            ]);
            return ['success' => false, 'to' => $to, 'error' => $err];
        }
    }

    public static function sendReport(array $r, string $pdfFile, string $jsonFile, string $fileBase, int $correction): array
    {
        $m = Settings::mail();
        $unit = Settings::unit();
        $date = fmt_date($r['start']);
        $subject = strtr((string) $m['subject_template'], [
            '{type}' => $r['type']['label'],
            '{number}' => $r['report_number'],
            '{subject}' => $r['subject'],
            '{location}' => $r['location'],
            '{date}' => $date,
            '{unit}' => $unit['name'],
        ]);
        if ($correction > 0) {
            $subject .= ' – Korrektur ' . $correction;
        }
        $lead = '';
        foreach ($r['personnel'] as $p) {
            if ($p['id'] === $r['lead']) {
                $lead = $p['name'];
            }
        }
        $closedBy = Pdf::closedByText($r);

        $lines = [
            $r['type']['label'] . ' ' . $r['report_number'] . ($correction ? ' (Korrektur ' . $correction . ')' : ''),
            '',
            'Datum:          ' . fmt_datetime($r['start']) . ($r['end'] ? ' – ' . fmt_datetime($r['end']) : ''),
            'Ort:            ' . $r['location'],
            'Thema:          ' . $r['subject'],
            'Leitung:        ' . $lead,
            'Abgeschlossen:  ' . $closedBy,
            '',
            'Im Anhang: Bericht als PDF und als JSON-Datei.',
            '',
            '– ' . ($unit['name'] ?: 'EÜDOK') . ' · EÜDOK',
        ];

        try {
            $mail = self::make();
            $mail->Subject = $subject;
            $mail->Body = implode("\n", $lines);
            if (is_file($pdfFile)) {
                $mail->addAttachment($pdfFile, $fileBase . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');
            }
            $mail->addAttachment($jsonFile, $fileBase . '.json', PHPMailer::ENCODING_BASE64, 'application/json');
        } catch (MailException $e) {
            Log::error('mail', 'prepare_failed', $e->getMessage(), ['report' => $r['report_number']]);
            return ['success' => false, 'to' => $m['recipients'], 'error' => $e->getMessage()];
        }
        return self::deliver($mail, $m['recipients']);
    }

    public static function sendText(array $to, string $subject, string $body): array
    {
        try {
            $mail = self::make();
            $mail->Subject = $subject;
            $mail->Body = $body;
        } catch (MailException $e) {
            Log::error('mail', 'prepare_failed', $e->getMessage(), ['subject' => $subject]);
            return ['success' => false, 'to' => $to, 'error' => $e->getMessage()];
        }
        return self::deliver($mail, $to);
    }
}
