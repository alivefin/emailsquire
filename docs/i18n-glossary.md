# EmailSquire translation glossary (English / Finnish)

This fixes the Finnish wording for the admin console. Use it before writing or changing a Finnish catalogue in `resources/js/i18n/fi/` or a Laravel lang file in `lang/fi/`.

Style: plain, polite and concise. Address the reader as "sinä" (second person singular), never "te". Prefer the imperative for buttons ("Tallenna"), and a full sentence for messages.

| English | Finnish | Usage |
|---|---|---|
| domain | verkkotunnus | Not "domain" or "toimialue". Plural "verkkotunnukset". |
| mailbox | postilaatikko | Not "sähköpostitili". Say "osoite" only for the address itself. |
| forwarding | edelleenlähetys | The feature. The verb form is "lähetetään edelleen". |
| keep a copy | säilytä kopio | Checkbox label on a forwarding rule: the mailbox keeps a copy as well. |
| forward only | lähetä vain edelleen | The portal's other choice beside "Säilytä kopio postilaatikossani": no copy stays in the mailbox. Its warning: "Jos osoite on väärä, postisi katoaa." |
| loop (forwarding) | silmukka | A forwarding target whose mail comes back to the mailbox: "… joten edelleenlähetyksestä sinne tulisi silmukka." |
| outside this server (forwarding) | tämän palvelimen ulkopuolinen | A forwarding target on no domain of this server: "Lähettää postia edelleen tämän palvelimen ulkopuolisiin osoitteisiin". The opposite in the portal: "tällä postipalvelimella". |
| alias (a mailbox owner's) | alias | An extra address on the mailbox's own domain that delivers only to that mailbox; the owner adds it in the portal. Inflects "aliaksen", "aliakset", "aliasta" ("enintään 20 aliasta"). Activity: "lisäsi postilaatikkoonsa aliaksen info@example.org", "poisti postilaatikostaan aliaksen …". |
| turned off by your administrator | ylläpitäjäsi on poistanut tämän käytöstä | A portal change the domain doesn't allow its mailbox owners: "Ylläpitäjäsi on poistanut tämän käytöstä." |
| not available (an address) | ei ole käytettävissä | The one answer a mailbox owner gets for an alias they can't take, whatever the reason: "Tämä osoite ei ole käytettävissä." |
| blocked address | estetty osoite | Not "kielletty" or "blokattu". |
| stop receiving mail (mailbox switch) | lopeta viestien vastaanotto | The optional `enabled` column (backend plan §10). Activity: "lopetti postilaatikon … viestien vastaanoton", "jatkoi postilaatikon … viestien vastaanottoa". |
| send-only (mailbox switch) | vain lähettävä | The optional `sendonly` column. Activity: "asetti postilaatikon … vain lähettäväksi", "poisti postilaatikon … vain lähettävän tilan". |
| catch-all | catch-all | kept untranslated (established term among Finnish mail admins) |
| catch-all forwarding | catch-all-edelleenlähetys | Forwarding of every address of a domain that has no mailbox or forwarding of its own (`@example.org`). Activity: "asetti verkkotunnukselle example.org catch-all-edelleenlähetyksen", "poisti verkkotunnuksen example.org catch-all-edelleenlähetyksen". |
| alias map (Postfix) | hakutaulu | A Postfix lookup table such as the guide's email2email map: "email2email-hakutaulu". Only in admin-facing messages. |
| storage limit | tallennustilan raja | Short form in tables and narrow columns: "Tallennustila". |
| quota "(default)" | (oletus) | Shown next to the default storage limit: "5 Gt (oletus)". |
| domain limits | verkkotunnuksen rajat | The per-domain caps set by a server admin. |
| maximum mailboxes | postilaatikoiden enimmäismäärä | How many mailboxes a domain may have. Message: "Tällä verkkotunnuksella voi olla enintään 5 postilaatikkoa." |
| total storage limit | tallennustilan yhteisraja | The most storage a domain's mailboxes may have in total. Message: "… voi varata yhteensä enintään 10 Gt tallennustilaa." |
| server admin | palvelimen ylläpitäjä | The role. |
| domain manager | verkkotunnuksen hallinnoija | The role. |
| viewer | katselija | The role. |
| manager | hallinnoija | Short form of "domain manager" where the domain is already named: "Lisäsi käyttäjän … verkkotunnukselle example.org hallinnoijaksi". Inflects as a noun: "hallinnoijaksi", "hallinnoijasta". |
| people (on a domain) | henkilöt | The people with access to a domain: column "Henkilöt", count "3 henkilöä". Not "ihmiset" or "käyttäjät". |
| users (console users) | käyttäjät | The Users screen: everyone who can sign in to the console. |
| view (a domain, read-only) | katsella | What a viewer can do: "Voit katsella tätä verkkotunnusta." Button "Näytä" opens a read-only view. |
| domain settings | verkkotunnuksen asetukset | The dialog where a server admin sets the domain limits, and admins and managers set what mailbox owners can change (viewers see it read-only). |
| mailbox owners' permissions (self-service) | postilaatikon omistajien oikeudet | What a domain lets the owner of a mailbox change in the portal (forwarding, aliases, external forwarding). Activity: "muutti verkkotunnuksen example.org postilaatikon omistajien oikeuksia". The Domain settings section: "Postilaatikon omistajat voivat…", with the switches "Lähettää postinsa edelleen", "Lisätä ja poistaa aliaksia". Not "itsepalvelu" in user-facing text. |
| allocated (storage) | varattu | Storage promised to mailboxes by their limits, used or not: "48 / 100 Gt varattu". |
| unlimited | rajaton | A limit left empty. Hint: "Jätä tyhjäksi, jos rajaa ei ole." |
| left (remaining) | jäljellä | "3 postilaatikkoa jäljellä", "12 Gt jäljellä". |
| access (to a domain) | käyttöoikeus | What a grant gives: "Palvelimen ylläpitäjillä on jo käyttöoikeus kaikkiin verkkotunnuksiin." Not "pääsy". |
| address | osoite | An email address. |
| invitation | kutsu | The emailed link that gives someone access. "Kutsulinkki" for the link itself. |
| invite | kutsu | Verb: "Mia kutsui sinut EmailSquireen." |
| accept (an invitation) | hyväksy | Button: "Hyväksy kutsu". |
| resend (an invitation) | lähetä uudelleen | Sends a new link; the old one stops working. |
| revoke (an invitation) | peru | "Kutsu on peruttu." Not "mitätöi". |
| pending (invitation) | odottaa hyväksymistä | Status of a sent invitation nobody has accepted yet. |
| expired | vanhentunut | A link or invitation past its time: "Tämä kutsu on vanhentunut." |
| deactivated (console user) | poistettu käytöstä | A console user who can't sign in: "Käyttäjä on poistettu käytöstä." |
| the whole server | koko palvelin | What a server-admin invitation gives: "Käyttöoikeus: koko palvelin". |
| share (a domain) | jaa | Giving someone access by invitation. Button: "Jaa käyttöoikeus". |
| remove access | poista käyttöoikeus | Ends a person's access to a domain. Your own, as a menu item: "Poista oma käyttöoikeuteni…"; in a question: "Poistetaanko oma käyttöoikeutesi …?" |
| role | rooli | A person's role on a domain (hallinnoija, katselija). "Vaihda rooli" changes it. |
| active (person on a domain) | aktiivinen | Status of a person whose access works. |
| added by | lisännyt | Who gave someone access: "lisännyt Laura Kivi · 8.10.2026". |
| note (in an invitation) | viesti | The optional text the inviter adds to the invitation mail. |
| email language | sähköpostin kieli | The language of the invitation mail. |
| invite user | kutsu käyttäjä | Button on the Users screen: "Kutsu käyttäjä". |
| last sign-in | viimeisin kirjautuminen | Column on the Users screen. A user who never signed in: "Ei koskaan". |
| deactivate / reactivate (console user) | poista käytöstä / ota uudelleen käyttöön | "Poista käytöstä…" and "Ota uudelleen käyttöön…" on a user's page. The state is "poistettu käytöstä". |
| reset two-factor | nollaa kaksivaiheinen tunnistautuminen | What a server admin does for a user who lost both the app and the recovery codes. |
| 2FA (badge) | 2FA | Only in the Users list's compact badge ("2FA käytössä", "2FA pois käytöstä"). Running text says "kaksivaiheinen tunnistautuminen". |
| change email | vaihda sähköpostiosoite | On a user's page. "Uusi sähköpostiosoite" for the field. |
| danger zone | vaaralliset toiminnot | The section with a permanent action (deleting a user). |
| domain role | verkkotunnuskohtainen rooli | A manager or viewer role on one domain, as opposed to the server admin role. |
| password | salasana | |
| display name | näyttönimi | |
| sign in | kirjaudu sisään | Button and heading. Noun form: "sisäänkirjautuminen". |
| sign out | kirjaudu ulos | |
| two-factor authentication | kaksivaiheinen tunnistautuminen | The feature. Not "kaksivaiheinen todennus" or "2FA" in running text. "Kaksivaiheisen tunnistautumisen koodi" for the 6-digit code. |
| recovery code | varakoodi | A one-time code for signing in without the authenticator app. Plural "varakoodit". |
| passkey | pääsyavain | A WebAuthn credential (a device's fingerprint, face or PIN) that signs in to the console. Plural "pääsyavaimet"; "pääsyavaimen", "pääsyavainta" ("enintään 10 pääsyavainta"). Activity: "lisäsi pääsyavaimen käyttäjälle …", "poisti käyttäjän … pääsyavaimen", "nimesi uudelleen käyttäjän … pääsyavaimen". |
| way to use two-factor (TOTP or a passkey) | tunnistautumistapa | The last-factor refusal: "… et voi poistaa viimeistä tunnistautumistapaasi." |
| copied (cloned) passkey | kopioitu pääsyavain | A passkey whose signature counter didn't go up; the app turns it off ("poistettiin käytöstä"). |
| signature counter | allekirjoituslaskuri | The passkey's use counter, named only in the security notice about a copied passkey. |
| authenticator app | todennussovellus | The phone app that shows the codes. |
| secret key | salainen avain | The two-factor secret typed into the authenticator app instead of scanning the QR code. |
| QR code | QR-koodi | |
| turn on / turn off (two-factor) | ota käyttöön / poista käytöstä | As for "enable" and "disable". Status: "Käytössä" / "Pois käytöstä". |
| regenerate (recovery codes) | luo uudet (varakoodit) | Button: "Luo uudet". The old codes stop working. |
| forgotten password | unohtunut salasana | The link on the sign-in screen: "Unohditko salasanasi?". |
| reset (a password) | nollaa (salasana) | "Salasanasi nollattiin." Noun: "salasanan nollaus". |
| reset link | palautuslinkki | The emailed link that sets a new password. Not "nollauslinkki". |
| change password | vaihda salasana | The signed-in user's own password change. "Nykyinen salasana" for the current one. |
| mailbox password (the owner's own) | postilaatikon salasana | What a mailbox owner changes in the portal. Activity, with the address as actor: "vaihtoi postilaatikkonsa salasanan". When the mail server can't check it: "Nykyistä salasanaasi ei voi juuri nyt tarkistaa. Yritä hetken kuluttua uudelleen." |
| sign out everywhere | kirjaudu ulos kaikkialta | Ends the user's other sessions. |
| mailbox owner | postilaatikon omistaja | The person who uses one mailbox and signs in to the portal with its password. Sign-in screen: "Postilaatikon omistaja: unohditko salasanasi? Kysy verkkotunnuksesi hallinnoijalta." |
| I want to manage my mailbox (sign-in choice) | haluan hallita postilaatikkoani | The sign-in card's checkbox that skips the console account. Its hint: "Valitse tämä, jos EmailSquire-tililläsi on sama salasana kuin postilaatikollasi." "EmailSquire-tili" is the console account, as in the account mails. |
| My mailbox (portal home) | Oma postilaatikko | The heading of the portal's home page. The portal's pages in its menu: "Etusivu", "Salasana", "Edelleenlähetys", "Aliakset", "Asetukset", "Ohjeet". The menu itself is "Postilaatikkosi". |
| storage (a mailbox's own) | tallennustila | In the portal: "1,5 Gt / 5 Gt käytetty". Unknown use: "Tallennustilan käyttöä ei voi juuri nyt näyttää." Nearly full: "lähes täynnä"; full: "täynnä". |
| email app settings | sähköpostiohjelman asetukset | The portal page with the server names and ports. "Palvelin", "Portti", "Suojaus" for its rows. |
| copy / copied | kopioi / kopioitu | The copy buttons: "Kopioi" on the button, "Kopioitu" once done; named: "Kopioi käyttäjätunnus", "Käyttäjätunnus kopioitu". |
| help and contact | ohjeet ja yhteystiedot | The portal page that names who to contact. "Verkkotunnuksesi hallinnoija auttaa sinua:" |
| confirm it's you | vahvista henkilöllisyytesi | The step-up dialog that asks for the password or a code again before a sensitive change. |
| session | istunto | A signed-in browser. |
| My profile | Oma profiili | The signed-in user's own settings page. |
| language | kieli | The interface language select on My profile. |
| setup link | käyttöönottolinkki | The one-time link from `emailsquire:create-admin`. Not "asetuslinkki". |
| disable | poista käytöstä | Reversible switch-off. Status: "Pois käytöstä". |
| enable | ota käyttöön | Status: "Käytössä". |
| delete | poista | Permanent removal. Never use "poista" for disable. |
| archive / trash | arkistoi / roskakori | "Arkistoi" for the mail folder archive. "Roskakori" for the trash that can be restored ("palauta"). |
| dashboard | yleisnäkymä | Not "kojelauta". |
| all addresses | kaikki osoitteet | |
| pinned | kiinnitetty | The verb is "kiinnitä", undo is "irrota kiinnitys". |
| activity log | tapahtumaloki | The screen that lists what happened (`/activity`) and its sidebar item; "Avaa tapahtumaloki →". Not "toiminta", which also reads as "operation" or "function". |
| activity (a list of entries) | tapahtumat | A domain's Activity tab ("Tapahtumat") and the dashboard panel ("Viimeisimmät tapahtumat"). One entry is "tapahtuma": "12 tapahtumaa". |
| action (activity filter) | tapahtumatyyppi | The activity log's filter by kind of change; its first option is "Kaikki tapahtumat". |
| object (activity column) | kohde | What an entry changed: an address, a domain, a user. |
| search | haku | The field label. The button and verb is "hae". |
| maildir task | maildir-tehtävä | A background task on a mailbox folder (archive, restore). |
| Not checked yet | Ei vielä tarkistettu | |
| DNS record | DNS-tietue | A record in a domain's DNS zone (MX, SPF, DKIM, DMARC, PTR). Plural "DNS-tietueet". Activity: "tarkisti verkkotunnuksen example.org DNS-tietueet". |
| DNS check | DNS-tarkistus | The checks of a domain's DNS records against this server. "Tarkista nyt" for the button that runs one on demand. |
| DKIM key | DKIM-avain | A domain's signing key; the console shows its selector and DNS record, never the key itself. Activity: "loi verkkotunnukselle example.org uuden DKIM-avaimen". |
| activate (a DKIM key) | ottaa käyttöön | rspamd starts signing with the key: "otti verkkotunnuksen example.org DKIM-avaimen es20261011 käyttöön". Not "aktivoida". |
| retire (a DKIM key) | poistaa | The key file is deleted: "poisti verkkotunnuksen example.org DKIM-avaimen es20250101". |
| selector (DKIM) | valitsin | The key's name in DNS (`es20261011._domainkey`). Usually shown as the value alone. |
| key (DKIM, short form) | avain | Where DKIM is already named, as on the DNS tab's DKIM card: "Luo uusi avain", "Avaimen tiedosto poistetaan palvelimelta." |
| not active (a DKIM key's state) | ei käytössä | A key that is not signing: new and waiting for Activate, or replaced by a newer one. Not "ei vielä käytössä", which is wrong for a replaced key. |
| MX preference number | prioriteettinumero | The number before an MX host; the lowest is tried first: "pienempi prioriteettinumero", "anna tälle palvelimelle pienin numero". |
| create (a DKIM key) | luoda | Button "Luo uusi avain". Not "generoida". |
| publish (a DNS record) | julkaista | What the domain's owner does at their DNS provider; the console only shows the record: "Julkaise tämä:", "Julkaise tietue ensin." |
| zone file line | vyöhyketiedoston rivi | The record written as one line of a BIND-style zone file, next to its value. |
| reverse DNS (PTR) | käänteinen DNS | The PTR record of the server's address: "PTR (käänteinen DNS)". |
| DNS check status | kunnossa / varoitus / ongelma / valinnainen | The badges of the DNS tab: ok, warn, fail, and info for the optional checks (MTA-STS, autoconfig). |
| switch labels (mailbox delivery) | Lopeta viestien vastaanotto / Vain lähettävä | The mailbox drawer's switches for the optional columns, on when the mailbox stops receiving or is send-only. Their toasts: "Postilaatikko {{address}} ei enää ota viestejä vastaan.", "Postilaatikko {{address}} ottaa taas viestejä vastaan." Mail through an alias or forwarding to such a mailbox "palautuu lähettäjälle". |
| shown once | näytetään vain kerran | Used for generated passwords: "Salasana näytetään vain kerran." |
| setup instructions | käyttöönotto-ohjeet | The welcome email to a mailbox's user: address, servers and ports, never the password. Button: "Lähetä käyttöönotto-ohjeet". |
| email app | sähköpostiohjelma | The program a mailbox's user reads mail with (Thunderbird, a phone's mail app). |
| webmail | verkkoposti | Reading mail in a web browser. Button in the welcome email: "Avaa verkkoposti". |
| username (of a mailbox) | käyttäjätunnus | What a mail app signs in with; for a mailbox it is the address. |
| incoming / outgoing mail | saapuva posti / lähtevä posti | The IMAP and SMTP settings: "Saapuva posti (IMAP)", "Lähtevä posti (SMTP)". |
| your administrator | ylläpitäjäsi | Whoever runs the mailbox, in mail to a mailbox's user: "Saat salasanan ylläpitäjältäsi." |
| English language switch | In English | Shown in the Finnish UI. |
| Finnish language switch | Suomeksi | Shown in the English UI. |

## Units

Byte sizes keep the number and use the locale's decimal separator ("4.2 GB" in English, "4,2 Gt" in Finnish), with a space before the unit.

| English | Finnish |
|---|---|
| PB | Pt |
| TB | Tt |
| GB | Gt |
| MB | Mt |
| KB | kt |
