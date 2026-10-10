# EmailSquire translation glossary (English / Finnish)

This fixes the Finnish wording for the admin console. Use it before writing or changing a Finnish catalogue in `resources/js/i18n/fi/` or a Laravel lang file in `lang/fi/`.

Style: plain, polite and concise. Address the reader as "sinä" (second person singular), never "te". Prefer the imperative for buttons ("Tallenna"), and a full sentence for messages.

| English | Finnish | Usage |
|---|---|---|
| domain | verkkotunnus | Not "domain" or "toimialue". Plural "verkkotunnukset". |
| mailbox | postilaatikko | Not "sähköpostitili". Say "osoite" only for the address itself. |
| forwarding | edelleenlähetys | The feature. The verb form is "lähetetään edelleen". |
| keep a copy | säilytä kopio | Checkbox label on a forwarding rule: the mailbox keeps a copy as well. |
| blocked address | estetty osoite | Not "kielletty" or "blokattu". |
| catch-all | catch-all | kept untranslated (established term among Finnish mail admins) |
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
| domain settings | verkkotunnuksen asetukset | The dialog where a server admin sets the domain limits. |
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
| password | salasana | |
| display name | näyttönimi | |
| sign in | kirjaudu sisään | Button and heading. Noun form: "sisäänkirjautuminen". |
| sign out | kirjaudu ulos | |
| two-factor authentication | kaksivaiheinen tunnistautuminen | The feature. Not "kaksivaiheinen todennus" or "2FA" in running text. "Kaksivaiheisen tunnistautumisen koodi" for the 6-digit code. |
| recovery code | varakoodi | A one-time code for signing in without the authenticator app. Plural "varakoodit". |
| authenticator app | todennussovellus | The phone app that shows the codes. |
| secret key | salainen avain | The two-factor secret typed into the authenticator app instead of scanning the QR code. |
| QR code | QR-koodi | |
| turn on / turn off (two-factor) | ota käyttöön / poista käytöstä | As for "enable" and "disable". Status: "Käytössä" / "Pois käytöstä". |
| regenerate (recovery codes) | luo uudet (varakoodit) | Button: "Luo uudet". The old codes stop working. |
| forgotten password | unohtunut salasana | The link on the sign-in screen: "Unohditko salasanasi?". |
| reset (a password) | nollaa (salasana) | "Salasanasi nollattiin." Noun: "salasanan nollaus". |
| reset link | palautuslinkki | The emailed link that sets a new password. Not "nollauslinkki". |
| change password | vaihda salasana | The signed-in user's own password change. "Nykyinen salasana" for the current one. |
| sign out everywhere | kirjaudu ulos kaikkialta | Ends the user's other sessions. |
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
| activity | toiminta | Heading for the activity list. |
| search | haku | The field label. The button and verb is "hae". |
| maildir task | maildir-tehtävä | A background task on a mailbox folder (archive, restore). |
| Not checked yet | Ei vielä tarkistettu | |
| shown once | näytetään vain kerran | Used for generated passwords: "Salasana näytetään vain kerran." |
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
