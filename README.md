# nl.onvergetelijk.gedrag

## Functionele beschrijving

De `gedrag`-extensie beheert gedragsnotities en aandachtspunten van deelnemers. Wanneer er bijzonderheden zijn rondom het gedrag van een deelnemer — denk aan agressie, zelfverwonding, pestgedrag of gebruik van medicatie — worden deze vastgelegd in gestructureerde velden die de kampstaf kan raadplegen.

De module verwerkt zowel een korte "shortlist" (een beknopte samenvatting van de aandachtspunten) als een uitgebreide "longlist" (de volledige beschrijvingen). Bij opslaan worden negatieve termen uit de shortlist opgeruimd, wordt de shortlist gesynchroniseerd naar de longlist, en wordt automatisch bepaald of de "issues"-vlag gezet moet worden — zodat de kampstaf direct ziet dat er aandacht nodig is.

De module heeft ook een cross-module medicatiedetectie: als in de medische module medicatie is vastgelegd, wordt dit automatisch meegewogen in de gedragsanalyse.

## Afhankelijkheden

- `nl.onvergetelijk.base`
- `nl.onvergetelijk.medisch` (cross-module medicatiedetectie)

---

## Technische documentatie

### Kernfuncties

- `gedrag_get_field_map()` — field map van gedrag-custom fields naar API-namen
- `gedrag_civicrm_customPre($op, $groupID, $entityID, &$params)` — pre-hook: extraheert gedragsvelden, roept `gedrag_civicrm_configure` aan en injecteert resultaat terug
- `gedrag_civicrm_configure($contact_id, $context, $params_gedrag)` — de hoofdmotor:
  1. Data inladen uit database
  2. Leidende waarden bepalen
  3. Opschonen: verwijder negatieve/lege termen uit shortlist
  4. Analyse gedragschecks (vergelijk shortlist en longlist, sync)
  5. Medicatiedetectie via cross-module check
  6. Overige logica (systeemvangnet voor onbekende situaties)
  7. Uitgebreide extrachecks
  8. Issues-vlag bepalen
  9. Opschonen en formatteren naar CiviCRM-formaat

### Shortlist vs. Longlist
- **Shortlist**: beknopte categorieën (tags) die snel scanbaar zijn voor kampstaf
- **Longlist**: uitgebreide vrije tekst per aandachtspunt
- Bij opslaan wordt de shortlist altijd gesynchroniseerd naar de longlist (`gedrag 3.1 FORCED SYNC`)

### Hooks geïmplementeerd
- `civicrm_customPre`
- `civicrm_config`, `civicrm_install`, `civicrm_enable`

---

*Beheerd door Stichting Onvergetelijke Zomerkampen.*
