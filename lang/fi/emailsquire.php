<?php

// Palvelimen viestit ylläpitokonsolille. Osoitteet ja nimet ovat dataa: ne annetaan paikkamerkkeinä
// eikä niitä koskaan käännetä. Virheiden `code`-arvot eivät muutu.
return [
    'address' => [
        'empty' => 'Kirjoita osoite.',
        'local_chars' => 'Käytä vain kirjaimia, numeroita, pisteitä, yhdysmerkkejä ja alaviivoja – ei välilyöntejä.',
        'domain_format' => 'Tämä ei näytä verkkotunnukselta.',
        'too_long' => 'Osoite saa olla enintään 100 merkkiä ja verkkotunnus enintään 50.',
        'not_ascii' => 'Käytä kirjaimia a–z ennen @-merkkiä.',
    ],
    'claims' => [
        'exists_mailbox' => ':address on jo olemassa.',
        'exists_forwarding' => ':address lähettää jo viestit edelleen – poista edelleenlähetys ensin.',
        'redirect_not_confirmed' => ':address on postilaatikko. Vahvista, että sen posti lähetetään vain edelleen.',
        'blocked' => ':address on estetty.',
        'blocked_reason' => ':address on estetty – syy: “:reason”.',
        'archiving' => ':address poistettiin äskettäin, ja sen postia arkistoidaan vielä. Yritä uudelleen muutaman minuutin kuluttua.',
    ],
    'conflict' => [
        'busy' => 'Joku muu muokkaa tätä osoitetta. Yritä uudelleen.',
        'not_failed' => 'Tehtävä :id ei ole epäonnistunut postikansion tehtävä.',
    ],
    'check' => [
        'mailbox' => ':address on jo postilaatikko.',
        'forwarding' => ':address lähettää viestit edelleen.',
        'available' => ':address on vapaana.',
    ],
    'problem' => [
        'csrf' => 'CSRF-tunniste puuttuu tai ei täsmää.',
        'too_many_requests' => 'Liikaa pyyntöjä. Yritä myöhemmin uudelleen.',
        'server_error' => 'Jokin meni pieleen.',
        'unauthenticated' => 'Kirjautuminen vaaditaan.',
        'session_expired' => 'Istuntosi on vanhentunut. Kirjaudu sisään uudelleen.',
        'cross_origin' => 'Pyynnöt toisesta alkuperästä eivät ole sallittuja.',
        'usage_unavailable' => 'Tallennustilan käyttö ei ole juuri nyt saatavilla.',
    ],
    'domain' => [
        'exists' => ':name on jo tällä palvelimella.',
        'confirm_name' => 'Vahvista kirjoittamalla :name.',
        'pin_limit' => 'Voit kiinnittää enintään :max verkkotunnusta.',
    ],
    'mailbox' => [
        'password_weak' => 'Liian heikko – käytä vähintään 12 merkkiä sekä numero tai erikoismerkki.',
        'password_too_long' => 'Käytä enintään 72 tavua.',
        'unknown' => 'Tuntematon postilaatikko: :locals.',
    ],
    'forwarding' => [
        'targets_required' => 'Lisää vähintään yksi kohde.',
        'targets_max' => 'Lähteestä voi lähettää edelleen enintään 50 kohteeseen.',
        'target_incomplete' => '“:value” ei ole täydellinen osoite.',
        'target_self' => 'Osoite ei voi toimittaa viestejä itselleen.',
    ],
    'blocked' => [
        'already_blocked' => 'Osoite on jo estetty.',
    ],
    'setup' => [
        'invalid_link' => 'Tämä asetuslinkki on virheellinen tai vanhentunut.',
    ],
];
