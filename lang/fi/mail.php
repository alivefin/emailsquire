<?php

// Email to console users. The recipient's `users.locale` picks the file; addresses and times are
// passed in as placeholders.
return [
    'reset' => [
        'subject' => 'Nollaa EmailSquire-salasanasi',
        'intro' => 'EmailSquire-tilisi salasanan nollausta pyydettiin. Valitse uusi salasana alla olevalla painikkeella.',
        'action' => 'Valitse uusi salasana',
        'expires' => 'Linkki toimii :minutes minuuttia ja sitä voi käyttää vain kerran.',
        'not_you' => 'Jos et pyytänyt tätä, voit jättää viestin huomiotta. Salasanasi pysyy ennallaan.',
        'link_text' => 'Jos painike ei toimi, kopioi tämä osoite selaimeesi:',
    ],
    'invitation' => [
        'subject' => 'Sinut on kutsuttu EmailSquireen',
        'greeting' => 'Hei,',
        'greeting_name' => 'Hei :name,',
        'intro' => ':inviter kutsui sinut EmailSquireen, tämän sähköpostipalvelimen ylläpitokonsoliin.',
        'access_domain' => 'Verkkotunnus: :domain',
        'access_server' => 'Käyttöoikeus: koko palvelin',
        'role' => 'Rooli: :role',
        'roles' => [
            'server_admin' => 'Palvelimen ylläpitäjä',
            'manager' => 'Verkkotunnuksen hallinnoija',
            'viewer' => 'Katselija',
        ],
        'note' => 'Viesti käyttäjältä :inviter:',
        'action' => 'Hyväksy kutsu',
        'expires' => 'Linkki on voimassa :hours tuntia, ja sitä voi käyttää vain kerran.',
        'not_you' => 'Jos et odottanut tätä kutsua, voit jättää viestin huomiotta.',
        'link_text' => 'Jos painike ei toimi, kopioi tämä osoite selaimeesi:',
    ],
    'security' => [
        'password_changed' => [
            'subject' => 'Salasanasi vaihdettiin',
            'what' => 'EmailSquire-tilisi salasana vaihdettiin.',
        ],
        'password_reset' => [
            'subject' => 'Salasanasi nollattiin',
            'what' => 'EmailSquire-tilisi salasana nollattiin palautuslinkillä.',
        ],
        'two_factor_disabled' => [
            'subject' => 'Kaksivaiheinen tunnistautuminen poistettiin käytöstä',
            'what' => 'Kaksivaiheinen tunnistautuminen poistettiin käytöstä EmailSquire-tililtäsi.',
        ],
        'sessions_revoked' => [
            'subject' => 'Sinut kirjattiin ulos kaikkialta',
            'what' => 'EmailSquire-tilisi muut istunnot kirjattiin ulos.',
        ],
        'email_changed' => [
            'subject' => 'Sähköpostiosoitteesi vaihdettiin',
            'what' => 'EmailSquire-tilisi sähköpostiosoite vaihdettiin. Et voi enää kirjautua sisään tällä osoitteella.',
        ],
        'when' => 'Aika: :time',
        'from_ip' => 'IP-osoite: :ip',
        'from_unknown' => 'IP-osoitetta ei tiedetä.',
        'not_you' => 'Jos et tehnyt tätä itse, ota yhteyttä palvelimen ylläpitäjään.',
    ],
];
