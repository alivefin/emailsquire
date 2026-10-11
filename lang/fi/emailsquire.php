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
    'access' => [
        'server_admin' => 'Palvelimen ylläpitäjillä on jo käyttöoikeus kaikkiin verkkotunnuksiin.',
    ],
    'users' => [
        'cannot_change_self' => 'Et voi tehdä tätä omalle tilillesi. Pyydä toista palvelimen ylläpitäjää tekemään se.',
        'last_server_admin' => 'Palvelimelle on jäätävä vähintään yksi palvelimen ylläpitäjä, jota ei ole poistettu käytöstä.',
    ],
    'invitation' => [
        'has_access' => 'Käyttäjällä :email on jo käyttöoikeus tähän verkkotunnukseen.',
        'pending' => 'Osoitteeseen :email on jo lähetetty kutsu, joka odottaa hyväksymistä.',
        'user_deactivated' => 'Käyttäjä :email on poistettu käytöstä.',
        'closed' => 'Tämä kutsu on jo hyväksytty tai peruttu.',
        'not_found' => 'Tämä kutsulinkki ei ole kelvollinen.',
        'sign_in_required' => 'Kirjaudu sisään käyttäjänä :email, niin voit hyväksyä tämän kutsun.',
        'wrong_account' => 'Tämä kutsu on osoitteelle :email. Kirjaudu ensin ulos ja avaa linkki sitten uudelleen.',
        'domain_required' => 'Valitse verkkotunnus tälle roolille.',
        'domain_unknown' => 'Tällä palvelimella ei ole tätä verkkotunnusta.',
        'state' => [
            'expired' => 'Tämä kutsu on vanhentunut. Pyydä uusi kutsu.',
            'used' => 'Tämä kutsu on jo käytetty.',
            'revoked' => 'Tämä kutsu ei ole enää voimassa.',
            'valid' => 'Tämä kutsu on voimassa.',
        ],
    ],
    'conflict' => [
        'busy' => 'Joku muu muokkaa tätä osoitetta. Yritä uudelleen.',
        'not_failed' => 'Tehtävä :id ei ole epäonnistunut maildir-tehtävä.',
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
        'cross_origin' => 'Pyynnöt toisilta sivustoilta eivät ole sallittuja.',
        'usage_unavailable' => 'Tallennustilan käyttö ei ole juuri nyt saatavilla.',
        'password_check_unavailable' => 'Nykyistä salasanaasi ei voi juuri nyt tarkistaa. Yritä hetken kuluttua uudelleen.',
        'step_up_required' => 'Jatka antamalla salasanasi tai kaksivaiheisen tunnistautumisen koodi.',
        'two_factor_required' => 'Ota kaksivaiheinen tunnistautuminen käyttöön, jotta voit jatkaa.',
    ],
    'domain' => [
        'exists' => ':name on jo tällä palvelimella.',
        'confirm_name' => 'Vahvista kirjoittamalla :name.',
        'confirm_name_idn' => 'Vahvista kirjoittamalla :unicode (tai :ascii).',
        'pin_limit' => 'Voit kiinnittää enintään :max verkkotunnusta.',
    ],
    'domain_limit' => [
        'mailboxes' => 'Tällä verkkotunnuksella voi olla enintään :max postilaatikko.|Tällä verkkotunnuksella voi olla enintään :max postilaatikkoa.',
        'quota' => 'Tämän verkkotunnuksen postilaatikoille voi varata yhteensä enintään :max tallennustilaa.',
    ],
    // Sizes as the console shows them (QuotaFormat::size()).
    'units' => [
        'gb' => ':n Gt',
        'mb' => ':n Mt',
        'decimal_separator' => ',',
    ],
    'mailbox' => [
        'password_weak' => 'Liian heikko – käytä vähintään 12 merkkiä sekä numero tai erikoismerkki.',
        'password_too_long' => 'Käytä enintään 72 tavua.',
        'password_nul' => 'Poista salasanasta NUL-merkki.',
        'unknown' => 'Tuntematon postilaatikko: :locals.',
    ],
    'forwarding' => [
        'targets_required' => 'Lisää vähintään yksi kohde.',
        'targets_max' => 'Osoitteesta voi lähettää viestit edelleen enintään 50 kohteeseen.',
        'target_incomplete' => '“:value” ei ole täydellinen osoite.',
        'target_self' => 'Osoite ei voi toimittaa viestejä itselleen.',
    ],
    'blocked' => [
        'already_blocked' => 'Osoite on jo estetty.',
    ],
    'step_up' => [
        'code_invalid' => 'Koodi on väärä tai vanhentunut.',
        'no_two_factor' => 'Kaksivaiheinen tunnistautuminen ei ole käytössä tililläsi. Käytä salasanaa.',
    ],
    'two_factor' => [
        'code_invalid' => 'Koodi on väärä tai vanhentunut.',
        'recovery_code_invalid' => 'Varakoodi on väärä tai jo käytetty.',
        'no_pending_sign_in' => 'Kirjaudu ensin sisään sähköpostiosoitteella ja salasanalla.',
        'pending_expired' => 'Sisäänkirjautuminen vanheni. Kirjaudu uudelleen sähköpostiosoitteella ja salasanalla.',
        'not_enabled' => 'Kaksivaiheinen tunnistautuminen ei ole käytössä.',
        'already_confirmed' => 'Kaksivaiheinen tunnistautuminen on jo käytössä.',
    ],
    'setup' => [
        'invalid_link' => 'Tämä käyttöönottolinkki on virheellinen tai vanhentunut.',
    ],
    'reset' => [
        'invalid_link' => 'Tämä palautuslinkki on virheellinen tai vanhentunut.',
    ],
];
