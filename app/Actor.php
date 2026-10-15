<?php
declare(strict_types=1);

namespace Euedok;

/** Wer handelt gerade? Angemeldeter Zugang oder QR-Dienstzugang. */
final class Actor
{
    public static function fromUser(array $u): array
    {
        $person = null;
        if (!empty($u['person_id']) && Settings::moduleOn('personnel')) {
            $p = MasterData::findPerson($u['person_id']);
            if ($p && !empty($p['active'] ?? true)) {
                $person = ['id' => $p['id'], 'name' => $p['name']] + (isset($p['divera_id']) ? ['divera_id' => $p['divera_id']] : []);
            }
        }
        return ['by' => $u['username'], 'by_name' => $u['display_name'], 'source' => null, 'person' => $person];
    }
}
