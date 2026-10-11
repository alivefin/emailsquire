<?php

// Email to console users, and the welcome email to a mailbox's user. The recipient's `users.locale`
// (the welcome email: the locale its sender chose) picks the file; addresses and times are passed in
// as placeholders.
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
    'welcome' => [
        'subject' => 'Postilaatikkosi :address on valmis',
        'intro' => 'Postilaatikkosi :address on valmis käyttöön. Alla ovat asetukset sähköpostiohjelmaasi varten.',
        'webmail' => 'Voit lukea postiasi myös selaimessa:',
        'webmail_action' => 'Avaa verkkoposti',
        'settings' => 'Sähköpostiohjelman asetukset',
        'username' => 'Käyttäjätunnus: :address',
        'imap' => 'Saapuva posti (IMAP): :server, SSL/TLS',
        'smtp' => 'Lähtevä posti (SMTP): :server, STARTTLS',
        'smtp_auth' => 'Lähtevä posti käyttää samaa käyttäjätunnusta ja salasanaa.',
        'password' => 'Saat salasanan ylläpitäjältäsi.',
        'not_you' => 'Jos et odottanut tätä viestiä, voit jättää sen huomiotta.',
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
        'passkey_added' => [
            'subject' => 'Pääsyavain lisättiin',
            'what' => 'EmailSquire-tilillesi lisättiin pääsyavain. Sillä voi kirjautua tilillesi.',
        ],
        'passkey_removed' => [
            'subject' => 'Pääsyavain poistettiin',
            'what' => 'EmailSquire-tililtäsi poistettiin pääsyavain.',
        ],
        'passkey_cloned' => [
            'subject' => 'Pääsyavain poistettiin käytöstä',
            'what' => 'Yhtä EmailSquire-pääsyavaimistasi käytettiin allekirjoituslaskurilla, joka ei kasvanut. Se voi tarkoittaa, että avain on kopioitu. Pääsyavain poistettiin käytöstä, eikä sillä voi enää kirjautua. Jos et odottanut tätä, poista se Oma profiili -sivulla ja vaihda salasanasi.',
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
