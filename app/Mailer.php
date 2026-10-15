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
        try {
            foreach ($to as $addr) {
                $mail->addAddress($addr);
            }
            if ($m['transport'] === 'file') {
                $mail->preSend();
                $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
                Storage::writeRaw('mail-outbox/' . $name, $mail->getSentMIMEMessage());
            } else {
                $mail->send();
            }
            return ['success' => true, 'to' => $to];
        } catch (MailException $e) {
            $err = $mail->ErrorInfo ?: $e->getMessage();
            Log::error('Mail: ' . $err);
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
            return ['success' => false, 'to' => $to, 'error' => $e->getMessage()];
        }
        return self::deliver($mail, $to);
    }
}
