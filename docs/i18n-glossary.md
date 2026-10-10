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
| server admin | palvelimen ylläpitäjä | The role. |
| domain manager | verkkotunnuksen hallinnoija | The role. |
| viewer | katselija | The role. |
| address | osoite | An email address. |
| password | salasana | |
| display name | näyttönimi | |
| sign in | kirjaudu sisään | Button and heading. Noun form: "sisäänkirjautuminen". |
| sign out | kirjaudu ulos | |
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
| GB | Gt |
| MB | Mt |
| KB | kt |
