<?php

require_once 'gedrag.civix.php';
use CRM_Intake_ExtensionUtil as E;

/**
 * =======================================================================================
 * COLOFON: gedrag_get_field_map (SINGLE SOURCE OF TRUTH)
 * =======================================================================================
 * @description     De centrale mapping voor alle Gedrag-gerelateerde custom fields binnen 
 * deze module. Koppelt de database-kolommen aan APIv4-namen.
 * @return array    Associatieve array in het format: ['db_naam_ID' => 'API.naam'].
 * =======================================================================================
 */
function gedrag_get_field_map(): array {
    return [
        'gedrag_issues_1984'      => 'GEDRAG.gedrag_issues',
        'gedrag_shortlist_1985'   => 'GEDRAG.gedrag_shortlist',
        'gedrag_longlist_1986'    => 'GEDRAG.gedrag_longlist',
        'gedrag_toelichting_1987' => 'GEDRAG.gedrag_toelichting',
        'gedrag_notities_1988'    => 'GEDRAG.gedrag_notities',
        'gedrag_check_1989'       => 'GEDRAG.gedrag_check',
        'gedrag_modified_2100'    => 'GEDRAG.gedrag_modified',
    ];
}

/**
 * =======================================================================================
 * COLOFON: gedrag_civicrm_customPre
 * =======================================================================================
 * @description     De "Portier" voor de Gedrag-module. Vangt formulierdata op, 
 * laat de motor rekenen en injecteert resultaten terug via base-helpers.
 * @trigger         Wordt getriggerd bij het opslaan van een Contact ('create' of 'edit').
 * =======================================================================================
 */
function gedrag_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    // --- STAP 0: PREVENTIE VAN DUBBELE UITVOERING ---
    static $processing_gedrag_pre = FALSE;
    if ($processing_gedrag_pre || !in_array($op, ['create', 'edit'])) {
        return;
    }

    $extdebug          = 0; // Zet op 1 of 3 voor uitgebreide logs
    $profilecontgedrag = [322]; 

    // Vroege afbreking als we niet in het juiste profiel zitten
    if (!in_array($groupID, $profilecontgedrag)) {
        return;
    }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG [PRE] 1.0 EXTRACTIE & MAPPING",                       "[MAP]");
    wachthond($extdebug, 1, "########################################################################");

    // --- STAP 1.0: EXTRACTIE VIA BASE HELPER ---
    $name_map      = gedrag_get_field_map();
    $field_ids     = base_get_field_ids($name_map);
    $params_gedrag = base_extract_from_params($params, $name_map);

    // Als we geen gedrags-velden hebben, hoeven we niets te doen.
    if (empty($params_gedrag)) {
        return;
    }

    $processing_gedrag_pre = TRUE;

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG [PRE] 2.0 START VERWERKING",                "[ID: $entityID]");
    wachthond($extdebug, 1, "########################################################################");

    // --- STAP 2.0: LOGICA UITBESTEDEN AAN DE REKENMACHINE ---
    // Context 'hook' zorgt dat we array terugkrijgen en hij niet zelf de DB update aanroept.
    $data_to_inject = gedrag_civicrm_configure($entityID, 'hook', $params_gedrag);

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG [PRE] 3.0 INJECTIE IN FORMULIER",               "[$entityID]");
    wachthond($extdebug, 2, "########################################################################");

    // --- STAP 3.0: RESULTATEN TERUGSTOPPEN IN HET FORMULIER ---
    if (!empty($data_to_inject)) {
        $success_list = base_inject_params($params, $data_to_inject, $field_ids, $entityID, "GEDRAG");

        if (!empty($success_list)) {
            wachthond($extdebug, 1, "GEDRAG [PRE] SUCCES: Injectie voltooid", $success_list);
        }
    }

    // --- STAP 4.0: DRUPAL DATUM CRASH VOORKOMEN ---
    if (function_exists('drupal_timestamp_sweep')) {
        drupal_timestamp_sweep($params);
    }

    wachthond($extdebug, 1, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG [PRE] EINDE VERWERKING",                        "[SUCCESS]");
    wachthond($extdebug, 1, "########################################################################");

    $processing_gedrag_pre = FALSE;
}

/**
 * =======================================================================================
 * COLOFON: gedrag_civicrm_configure
 * =======================================================================================
 * @description     De "Rekenmachine" voor Gedrag. Berekent statussen, synct medicatie
 * en genereert waarden om te injecteren/updaten.
 * =======================================================================================
 */
function gedrag_civicrm_configure(int $contact_id, string $context = 'direct', array $params_gedrag = []): array {

    // --- RECURSIE BEVEILIGING ---
    static $processing_gedrag_configure = FALSE;
    if ($processing_gedrag_configure || empty($contact_id)) {
        return [];
    }

    $extdebug = 0; 
    $processing_gedrag_configure = TRUE;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG CONFIGURE - 1.0 DATA INLADEN UIT DATABASE",     "[FALLBACK]");
    wachthond($extdebug, 2, "########################################################################");

    // 1. Haal de huidige situatie op (noodzakelijk voor cross-module data zoals medisch).
    try {
        $result_contact_get = civicrm_api4('Contact', 'get', [
            'checkPermissions' => FALSE,
            'select'           => [
                'display_name', 
                'Curriculum.Laatste_keer',      
                'MEDISCH.medisch_toelichting',  
                'MEDISCH.medisch_medicatie',    
                'GEDRAG.gedrag_issues',
                'GEDRAG.gedrag_toelichting', 
                'GEDRAG.gedrag_notities',    
                'GEDRAG.gedrag_check',
                'GEDRAG.gedrag_modified',
                'GEDRAG.gedrag_shortlist',   
                'GEDRAG.gedrag_longlist'
            ],
            'where'            => [['id', '=', $contact_id]],
            'limit'            => 1,
        ])->first();
    } catch (\Exception $e) {
        wachthond(1, 1, "GEDRAG FETCH ERROR: " . $e->getMessage());
        $result_contact_get = [];
    }

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG CONFIGURE - 2.0 BEPAAL LEIDENDE WAARDEN",       "[INPUT]");
    wachthond($extdebug, 2, "########################################################################");

    // 2. Formulierinput (params_gedrag) overschrijft Database (result_contact_get).
    $val_toelichting     = $params_gedrag['GEDRAG.gedrag_toelichting'] ?? $result_contact_get['GEDRAG.gedrag_toelichting'] ?? '';
    $val_notities        = $params_gedrag['GEDRAG.gedrag_notities']    ?? $result_contact_get['GEDRAG.gedrag_notities']    ?? '';

    // Medisch is puur voor de checks (wordt niet bewerkt op dit formulier).
    $val_med_toelichting = $result_contact_get['MEDISCH.medisch_toelichting'] ?? '';
    $val_med_medicatie   = $result_contact_get['MEDISCH.medisch_medicatie']   ?? '';

    // Ruwe data (De extract helper geeft nette strings terug met \x01 separators indien multi-select)
    $raw_shortlist       = $params_gedrag['GEDRAG.gedrag_shortlist'] ?? $result_contact_get['GEDRAG.gedrag_shortlist'] ?? [];
    $raw_longlist        = $params_gedrag['GEDRAG.gedrag_longlist']  ?? $result_contact_get['GEDRAG.gedrag_longlist']  ?? [];

    // Normaliseren naar PHP Arrays voor de logica
    $lists = [];
    $lists['gedrag_shortlist'] = is_array($raw_shortlist) ? $raw_shortlist : array_filter(explode("\x01", trim($raw_shortlist, "\x01")));
    $lists['gedrag_longlist']  = is_array($raw_longlist)  ? $raw_longlist  : array_filter(explode("\x01", trim($raw_longlist, "\x01")));

    $haystack_gedrag = $val_toelichting . ' ' . $val_notities . ' ' . $val_med_toelichting . ' ' . $val_med_medicatie;

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG CONFIGURE - 2.1 OPSCHONEN NEGATIEVE TERMEN",    "[CLEANUP]");
    wachthond($extdebug, 2, "########################################################################");

    // We verwijderen termen als 'nee', 'geen', 'nvt' uit de Gedrag-velden.
    // Dit voorkomt dat 'geen autisme' wordt gezien als 'autisme'.
    $negatieve_termen = ['nee', 'niets', 'geen', 'n.v.t.', 'nvt', 'niet van toepassing', '-', 'x', 'leeg', 'ik heb geen aandachtspunten'];
    $fields_to_clean  = [
        'GEDRAG.gedrag_toelichting' => &$val_toelichting, 
        'GEDRAG.gedrag_notities'    => &$val_notities
    ];

    foreach ($fields_to_clean as $f_name => &$val) {
        // 1. Maak input klein (lowercase)
        // 2. Trim spaties ÉN punten weg (zodat "Nee." ook matcht met "nee")
        $clean_val = trim(strtolower((string)$val), " .");
        
        if (in_array($clean_val, $negatieve_termen)) {
            wachthond($extdebug, 3, "OPSCHONEN: Negatieve term gevonden in $f_name");
            $val = '';
        }
    }
    unset($val); // Veiligheid: referentie verbreken

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG CONFIGURE - 3.0 ANALYSE GEDRAGSCHECKS",         "[SYNC]");
    wachthond($extdebug, 2, "########################################################################");

    // Haal op welke opties er daadwerkelijk bestaan in de database voor de shortlist.
    $valid_shortlist_options    = get_valid_options('gedrag_shortlist', 322);
    $valid_longlist_options     = get_valid_options('gedrag_longlist',  322);

    // =========================================================================
    // CONFIGURATIE: ZOEKTERMEN PER DIAGNOSE (STAP 3.0)
    // =========================================================================
    // Let op: Spaties rondom korte termen (' add ') voorkomen matches in andere woorden.
    // Typfouten (zoals 'automulatie') zijn bewust behouden om fonetische input te vangen.
    
    $gedrag_checks = [
        'add'               => [' add ', ' add.', 'ADD'], 
        'adhd'              => ['adhd', 'a.d.h.d.', 'hyperactief', 'hyperactiviteit'],
        'agressie'          => ['agressie', 'agressief', 'agresie', 'fysiek reageren', 'driftig', 'woede', ' odd ', 'grensoverschrijdend'],
        'asperger'          => ['asperger'],
        'autisme'           => ['autisme', ' ass ', 'autistisch', 'spectrumstoornis'],
        'bedplassen'        => ['bedplassen','laten plassen',' bed plassen',' plast in bed ','zindelijk','zindelijkheid',' plasluier ',' luier'],
        'depressief'        => ['depressief', 'depressie', 'neerslachtig', 'stemmingsstoornis', 'depresief', 'depressiva'],
        'dyslexie'          => ['dyslexie', 'dyslectisch', 'dyscalculie', 'dislexie'],
        'fas'               => [' fas ', 'foetaal alcohol', 'alcohol syndroom'],
        'gescheiden'        => ['gescheiden', 'scheiding', 'co-ouderschap', 'omgangsregeling', 'vechtscheiding'],
        'gezinshuis'        => ['gezinshuis'],
        'hechting'          => ['hechting', 'hechtingsproblematiek', 'hechtingsstoornis', 'hechtingstoornis', 'onveilig gehecht'],
        'heimwee'           => ['heimwee'],
        'hoogbegaafd'       => ['hoogbegaafd', 'hoog begaafd'],    
        'hoogsensitief'     => ['hoogsensitief', 'hoogsensitiviteit', 'hoogsensitieviteit', ' hsp ', 'hooggevoelig', 'hooggevoeligheid'],    
        'overgeslagen'      => ['klas overgeslagen', 'groep overgeslagen', 'versneld naar'],
        'overlijden'        => ['overlijden', 'overleden', 'dood van', 'begrafenis', 'gecremeerd', 'verlies van'],
        'paniek'            => ['paniekaanval', 'paniek aanval', 'paniekstoornis'],
        'pddnos'            => ['pddnos', 'pdd-nos', 'PDD-NOS'],
        'pleeggezin'        => ['pleeggezin', 'pleegzorg', 'pleegdochter', 'pleegzoon', 'pleegouders'],
        'ptss'              => ['ptss', 'cptss', 'post traumatisch', 'trauma', 'trauma behandeling', 'emdr', 'flashbacks'],
        'slaapwandelen'     => ['slaapwandelen', 'slaapwandelaar'],
        'somber'            => ['somber', 'somberheid', 'niet lekker in vel', 'teruggetrokken'],
        'speciaalonderwijs' => ['speciaal onderwijs', ' vso ', ' sbo ', 'cluster 3', 'cluster 4', 'lwoo', 'praktijkonderwijs'],
        'suicidaal'         => ['suicidaal', 'suïcidaal', 'suicide', 'suïcide', 'zelfdoding', 'zelfmoord', 'suïsidaal', 'zelfmoordneigingen'],
        'tos'               => ['taalontwikkelingsstoornis', 'taalontwikkeling', ' tos ', 'Taal Ontwikkelings Stoornis'],
        'zelfverwonding'    => ['zelfverwonding', 'automutilatie', 'automulatie', 'snijdt zichzelf', 'krast zichzelf', 'zichzelf beschadigen']
    ];

    // Helper functie check (compatibiliteit)
    $str_contains_func = function_exists('str_contains_any_reporting') ? 'str_contains_any_reporting' : function($haystack, $needles) {
        foreach((array)$needles as $n) { if (stripos($haystack, $n) !== false) return true; } return false;
    };

    foreach ($gedrag_checks as $check_key => $needles) {
        if ($str_contains_func($haystack_gedrag, $needles, false)) {
            // Match gevonden!
            if (!in_array($check_key, $lists['gedrag_longlist'])) { $lists['gedrag_longlist'][] = $check_key; }
            
            // Auto-vink shortlist als het een geldige optie is
            if (in_array($check_key, $valid_shortlist_options) && !in_array($check_key, $lists['gedrag_shortlist'])) {
                $lists['gedrag_shortlist'][] = $check_key;
            }
        } else {
            // Match NIET gevonden -> Verwijder uit longlist als hij ook niet op shortlist staat (handmatig)
            // Dit houdt de longlist schoon.
            if (!in_array($check_key, $lists['gedrag_shortlist'])) {
                if (($fK = array_search($check_key, $lists['gedrag_longlist'])) !== false) {
                    unset($lists['gedrag_longlist'][$fK]);
                }
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 3.1 FORCED SYNC (SHORTLIST -> LONGLIST)",             "[SYNC]");
    wachthond($extdebug, 3, "########################################################################");

    // Als een item op de shortlist staat (handmatig aangevinkt of uit DB), 
    // moet hij ALTIJD ook op de longlist staan, ongeacht de tekst-analyse.
    if (!empty($lists['gedrag_shortlist'])) {
        foreach ($lists['gedrag_shortlist'] as $short_item) {
            
            // Check: Staat hij nog niet op de longlist?
            if (!in_array($short_item, $lists['gedrag_longlist'])) {
                
                // Validatie: Mag hij op de longlist? (Voorkomt database errors)
                if (isset($valid_longlist_options) && in_array($short_item, $valid_longlist_options)) {
                    $lists['gedrag_longlist'][] = $short_item;
                    wachthond($extdebug, 1, "SYNC: '$short_item' gekopieerd van shortlist naar longlist.");
                } else {
                    wachthond($extdebug, 3, "SYNC FOUT: '$short_item' staat op shortlist maar is geen optie voor longlist.");
                }
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 4.0. MEDICATIE DETECTIE (CROSS-MODULE)",         "[MEDICATIE]");
    wachthond($extdebug, 3, "########################################################################");

    // Hier koppelen we medicijnen (gevonden in de medische module) aan gedrags-diagnoses.
    $med_naar_gedrag = [
        'adhd'       => [
            // Stimulantia: bijna uitsluitend voor ADHD/ADD gebruikt
            'amfexa', 'concerta', 'dexamfetam', 'dexamfetamine', 'elvanse', 
            'equasym', 'kinecteen', 'medikinet', 'methylfenidaat', 'ritalin', 'tentin'
        ],
        'add'        => [
            // Specifieke non-stimulantia voor focus
            'atomoxetine', 'stratera', 'strattera'
        ],
        'autisme'    => [
            // Hoewel breed ingezet, vaak de enige medicatie bij ASS-prikkelgevoeligheid
            'dipiperon', 'pipamperon', 'risperdal', 'risperidon'
        ],
        'bedplassen' => [
            // Zeer specifieke indicatie
            'desmopressine', 'minrin'
        ],
        'depressief' => [
            // Brede groep, maar duidt zonder twijfel op stemming/angst (notities/medicatie)
            'amitriptyline', 'antidepressiva', 'bupropion', 'citalopram', 'clomipramine', 
            'duloxetine', 'escitalopram', 'fluoxetine', 'fluvoxamine', 'lithium', 
            'nortriptyline', 'paroxetine', 'prozac', 'sertraline', 'venlafaxine', 'wellbutrin'
        ],
        'ptss'       => [
            // Prazosine wordt in de psychiatrie specifiek voor PTSS-nachtmerries gegeven
            'prazosine'
        ]
    ];

    // -------------------------------------------------------------------------
    // ITERATIE: Loop door alle gedefinieerde medicijn-groepen
    // -------------------------------------------------------------------------
    foreach ($med_naar_gedrag as $diag => $medicijnen) {

        // DETECTIE: Zoek of één van de medicijnen voorkomt in de opgeschoonde tekst.
        // We gebruiken 'false' voor case-insensitive match (extra zekerheid).
        $match_gevonden = str_contains_any_reporting($haystack_gedrag, $medicijnen, false);

        if ($match_gevonden) {
            
            // LOGGING: We scheiden titel en data voor een rustig logbeeld.
            wachthond($extdebug,3, "MATCH GEVONDEN", "$match_gevonden -> $diag");

            // ------------------------------------------------------------------
            // 1. LONGLIST UPDATE (DATABASE OPSLAG)
            // ------------------------------------------------------------------
            // We controleren eerst of de gevonden diagnose ($diag) technisch bestaat 
            // in de CiviCRM optielijst ($valid_longlist_options).
            // TECHNISCH: Dit voorkomt "Illegal Choice" errors bij het opslaan.
            if (isset($valid_longlist_options) && in_array($diag, $valid_longlist_options)) {
                
                // Voeg toe aan de interne lijst als hij er nog niet in staat.
                // FUNCTIONEEL: Voorkomt duplicaten in de array.
                if (!in_array($diag, $lists['gedrag_longlist'])) { 
                    $lists['gedrag_longlist'][] = $diag;
                    wachthond($extdebug,3, "LONGLIST-UPDATE [SUCCES]", "'$diag' toegevoegd.");
                }
            } else {
                // Als de key niet bestaat in CiviCRM (bijv. typo in array), loggen we een fout.
                wachthond($extdebug,4, "FOUT: NIET IN LONGLIST", "'$diag' is geen valide optie.");
            }

            // ------------------------------------------------------------------
            // 2. SHORTLIST UPDATE (ZICHTBARE VINKJES OP FORMULIER)
            // ------------------------------------------------------------------
            // Niet alles wat op de longlist staat, heeft ook een checkbox op de shortlist.
            // We checken dus apart tegen $valid_shortlist_options.
            if (isset($valid_shortlist_options) && in_array($diag, $valid_shortlist_options)) {
                
                if (!in_array($diag, $lists['gedrag_shortlist'])) {
                    $lists['gedrag_shortlist'][] = $diag;
                    wachthond($extdebug,3, "SHORTLIST-UPDATE [SUCCES]", "'$diag' aangevinkt.");
                }
            } else {
                // FUNCTIONEEL: Dit is info. We hebben wel een diagnose (dus opgeslagen in longlist), 
                // maar de gebruiker ziet geen vinkje omdat het veld niet op het formulier staat.
                wachthond($extdebug,4, "INFO: NIET OP SHORTLIST", "'$diag' heeft geen vinkje.");
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 1, "### GEDRAG 5.0. OVERIG LOGICA (SYSTEEM-VANGNET)                : [OVERIG]");
    wachthond($extdebug, 3, "########################################################################");

    // -------------------------------------------------------------------------
    // ANALYSE: IS ER SPECIFIEKE GEDRAGS-INPUT?
    // -------------------------------------------------------------------------
    // We kijken hier NIET naar de volledige haystack (want daar zit ook medisch in).
    // 'Overig' mag alleen getriggerd worden als er daadwerkelijk in de gedrags-velden is getypt.
    
    $raw_user_input = $val_toelichting . ' ' . $val_notities;
    
    // Schoonmaken (HTML tags weg, onzichtbare tekens weg, trimmen)
    $clean_input = html_entity_decode(strip_tags($raw_user_input), ENT_QUOTES, 'UTF-8');
    $clean_input = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $clean_input);
    $clean_input = trim($clean_input);
    
    // De cruciale check: Is er écht iets ingevuld door de gebruiker in de Gedrag-velden?
    $has_user_behavior_input = (strlen($clean_input) > 0);

    if ($has_user_behavior_input) {
        wachthond($extdebug, 5, "GEDRAG: Gebruiker heeft tekst ingevuld (" . strlen($clean_input) . " chars).");
    } else {
        wachthond($extdebug, 5, "GEDRAG: Gedrags-velden zijn leeg (geen 'Overig' trigger).");
    }

    // -------------------------------------------------------------------------
    // SECTIE A: TOEVOEGEN VAN 'OVERIG'
    // -------------------------------------------------------------------------
    
    // 1. Shortlist: Alleen toevoegen als er INPUT is, maar geen VINKJES.
    if (empty($lists['gedrag_shortlist']) && $has_user_behavior_input) {
        if (!in_array('overig', $lists['gedrag_shortlist'])) {
            $lists['gedrag_shortlist'][] = 'overig';
            wachthond($extdebug, 2, "GEDRAG: 'overig' op shortlist gezet (wel tekst, geen vinken).");
        }
    }

    // 2. Longlist: Alleen toevoegen als er INPUT is, maar geen MATCHES.
    if (empty($lists['gedrag_longlist']) && $has_user_behavior_input) {
        if (!in_array('overig', $lists['gedrag_longlist'])) {
            $lists['gedrag_longlist'][] = 'overig';
            wachthond($extdebug, 2, "GEDRAG: 'overig' op longlist gezet (wel tekst, totaal geen match).");
        }
    }

    // -------------------------------------------------------------------------
    // SECTIE B: CLEANUP (VERWIJDEREN VAN 'OVERIG')
    // -------------------------------------------------------------------------
    
    // SCENARIO 1: Velden zijn leeggemaakt
    // Als 'Overig' erin staat, maar er is GEEN tekst input meer, moet hij weg.
    if (!$has_user_behavior_input) {
        // Shortlist opschonen
        if (($key = array_search('overig', $lists['gedrag_shortlist'])) !== false) {
             unset($lists['gedrag_shortlist'][$key]);
             wachthond($extdebug, 2, "CORRECTIE: 'overig' verwijderd van shortlist (velden zijn leeg).");
        }
        // Longlist opschonen
        if (($key = array_search('overig', $lists['gedrag_longlist'])) !== false) {
             unset($lists['gedrag_longlist'][$key]);
             wachthond($extdebug, 2, "CORRECTIE: 'overig' verwijderd van longlist (velden zijn leeg).");
        }
    }

    // SCENARIO 2: Conflict Cleanup
    // Als we een specifieke diagnose hebben (bijv. ADHD), is 'Overig' niet meer nodig.
    
    // Cleanup Shortlist
    if (count($lists['gedrag_shortlist']) > 1 && in_array('overig', $lists['gedrag_shortlist'])) {
        $fK = array_search('overig', $lists['gedrag_shortlist']);
        unset($lists['gedrag_shortlist'][$fK]);
        wachthond($extdebug, 2, "CLEANUP: 'overig' verwijderd van shortlist (specifieke diagnose aanwezig).");
    }

    // Cleanup Longlist
    if (count($lists['gedrag_longlist']) > 1 && in_array('overig', $lists['gedrag_longlist'])) {
        $fK = array_search('overig', $lists['gedrag_longlist']);
        unset($lists['gedrag_longlist'][$fK]);
        wachthond($extdebug, 2, "CLEANUP: 'overig' verwijderd van longlist (specifieke diagnose aanwezig).");
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 6.0. UITGEBREIDE EXTRACHECK SECTIE                  : [CHECKS]");
    wachthond($extdebug, 3, "########################################################################");

    // =========================================================================
    // CONFIGURATIE: DEFINITIES VOOR STOPLICHT STATUS
    // =========================================================================
    // Status 0 = Groen (Geen bijzonderheden)
    // Status 1 = Oranje (Aandachtspunt)
    // Status 2 = Rood (Hoog risico / Directe actie nodig)
    
    // LIJST A: HARDE DIAGNOSES (Triggeren direct Status 2)
    // Alfabetisch gesorteerd
    $extra_check_diagnoses = [
        'agressie', 'autisme', 'depressief', 'hechting', 'paniek', 'ptss', 'suicidaal', 'tos'
    ];
    
    // LIJST B: KEYWORDS (Substrings)
    // Triggeren op deel van een woord (bijv. 'misbruik' matcht ook 'misbruikverleden')
    
    // ORANJE KEYWORDS (Status 1) - Alfabetisch
    $keywords_status_1 = [ 
        'amitriptyline', 'antidepressiva', 'antisociaal', 'bupropion', 'carbamazepine',
        'clomipramine', 'depressie', 'depressief', 'depressiva', 
        'depressiviteit', 'driftig', 'duloxetine', 'escitalopram', 
        'faalangst', 'fluoxetine', 'fluvoxamine', 'hechtingsproblematiek', 'jeugdzorg', 
        'lithium', 'mishandeling', 'nortriptyline', 'oxazepam', 
        'paroxetine', 'persoonlijkheidsstoornis', 'prazepam', 'pregabaline', 'prozac', 
        'psycholoog', 'sertraline', 'valproaat', 'venlafaxine'
    ];

    // ROOD KEYWORDS (Status 2) - Alfabetisch
    $keywords_status_2 = [ 
        'afkick', 'aggresief', 'agresie', 'agressie', 'alprazolam',
        'angststoornis', 'antipsychotica', 'antipsychotische', 'aripiprazol', 'automutilatie',
        'campral', 'clozapine', 'cyproteronactetaat', 'decapeptyl', 'esketamine', 
        'lamotrigine', 'lorazepam', 'manipuleren', 'misbruik', 'mishandeld', 
        'normoverschrijdend', 'oestradiol', 'pamerolin', 'paniek', 'paniekaanval', 
        'pipamperon', 'psychose', 'psychotisch', 'quetiapine', 'risperidon', 
        'selincro', 'spironolacton', 'uithuisplaatsing', 'veilig thuis', 'verslaving', 
        'woede', 'xanax'
    ];

    // LIJST C: STRICT WORDS (Hele woorden)
    // Triggeren alleen op exacte woordmatch (bijv. 'ass' matcht niet op 'passie')
    
    // ORANJE STRICT (Status 1) - Alfabetisch
    $strict_status_1 = ['betast', 'liegen', 'onveilig', 'poh', 'sint-janskruid', 'tics'];
    
    // ROOD STRICT (Status 2) - Alfabetisch
    $strict_status_2 = ['ass', 'benzo', 'cannabis', 'hsp', 'odd', 'vth'];

    // Initieel op groen zetten
    $new_check_status = "0"; 

    // =========================================================================
    // STAP 1: SAMENVOEGEN LONGLIST & SHORTLIST
    // =========================================================================
    // FUNCTIONEEL: Soms staat een diagnose alleen op de shortlist (vinkje) en niet 
    // op de longlist, of andersom. We moeten in BEIDE lijsten kijken.
    $alle_gevonden_diagnoses = array_unique(array_merge(
        $lists['gedrag_longlist'] ?? [], 
        $lists['gedrag_shortlist'] ?? []
    ));

    // DEBUG: Laat zien wat we gevonden hebben vóór de check
    $debug_diagnoses_str = implode(', ', $alle_gevonden_diagnoses);
    wachthond($extdebug, 2, "DEBUG CHECK", "Gevonden diagnoses om te toetsen: [$debug_diagnoses_str]");

    // Check of er matches zijn met de 'Harde Diagnoses' (Lijst A)
    $matching_extra = array_intersect($alle_gevonden_diagnoses, $extra_check_diagnoses);
    
    // =========================================================================
    // STAP 2: DE BESLISBOOM (PRIORITEIT: ROOD > ORANJE > GROEN)
    // =========================================================================

    // 1. Check op Harde Diagnoses (Status 2)
    if (!empty($matching_extra)) {
        $new_check_status = "2";
        $gevonden = implode(', ', $matching_extra);
        wachthond($extdebug, 1, "STATUS UPDATE [ROOD]",     "Via diagnose-lijst: $gevonden");
    } 
    // 2. Check op Keywords Rood (Status 2) - let op de toekenning ($found =) in de if
    elseif ($found = str_contains_any_reporting($haystack_gedrag, $keywords_status_2, false)) {
        $new_check_status = "2";
        wachthond($extdebug, 1, "STATUS UPDATE [ROOD]",     "Via keyword (deel): '$found'");
    } 
    // 3. Check op Strict Words Rood (Status 2)
    elseif ($found = str_contains_word_reporting($haystack_gedrag, $strict_status_2)) {
        $new_check_status = "2";
        wachthond($extdebug, 1, "STATUS UPDATE [ROOD]",     "Via strict woord: '$found'");
    } 
    // 4. Check op Keywords Oranje (Status 1)
    // Alleen checken als we nog niet op rood staan (wat door de elseif structuur al zo is)
    elseif ($found = str_contains_any_reporting($haystack_gedrag, $keywords_status_1, false)) {
        $new_check_status = "1";
        wachthond($extdebug, 1, "STATUS UPDATE [ORANJE]",   "Via keyword (deel): '$found'");
    } 
    // 5. Check op Strict Words Oranje (Status 1)
    elseif ($found = str_contains_word_reporting($haystack_gedrag, $strict_status_1)) {
        $new_check_status = "1";
        wachthond($extdebug, 1, "STATUS UPDATE [ORANJE]",   "Via strict woord: '$found'");
    } 
    else {
        // Geen enkele match
        wachthond($extdebug, 2, "STATUS UPDATE [GROEN]",    "Geen risico-factoren gevonden.");
    }

    // =========================================================================
    // STAP 3: RESULTAAT OPSLAAN VOOR VOLGENDE STAPPEN
    // =========================================================================
    $lists['gedrag_check_status'] = $new_check_status;

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 7.0. BEPAAL OF ISSUES AAN MOET",                     "[ISSUES]");
    wachthond($extdebug, 3, "########################################################################");

    // Issues Vlag Bepaling:
    // 1. Is er tekst?
    $has_text = (trim((string)$val_toelichting) !== '' || trim((string)$val_notities) !== '');
    
    // 2. Zijn er lijsten gevuld? (Handmatig of door onze auto-detectie)
    $has_lists = (!empty($lists['gedrag_shortlist']) || !empty($lists['gedrag_longlist']));

    // Conclusie
    $has_issues = ($has_text || $has_lists) ? "1" : "0";

    // Debugging
    if ($has_issues === "1") {
        wachthond($extdebug, 5, "GEDRAG: Issues geforceerd op JA (Tekst: " . ($has_text ? 'Ja' : 'Nee') . ", Lijsten: " . ($has_lists ? 'Ja' : 'Nee') . ")");
    } else {
        wachthond($extdebug, 5, "GEDRAG: Geen issues gevonden (Alles leeg).");
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 8.0. OPSCHONEN & FORMATTEREN (NAAR CIVI-FORMAT)",   "[SMART]");
    wachthond($extdebug, 3, "########################################################################");

    // Lijsten uniek maken en filteren (lege waarden eruit)
    foreach ($lists as $key => &$list) {
        $filtered = array_filter((array)$list, function($v) {
            $val = trim((string)$v);
            return !empty($val) && $val !== '' && strtolower($val) !== 'array';
        });
        $list = array_values(array_unique($filtered));
        wachthond($extdebug, 5, "CLEANUP [$key]: Bevat nu " . count($list) . " items.");
    }    
    
    // DATA PREPAREREN VOOR DE VERZAMELAAR (Base Inject / Base API Wrapper)
    // Let op: Base API Wrapper en Inject Params verwachten schone PHP arrays voor multi-selects!
    $data_to_inject = [
        'GEDRAG.gedrag_issues'      => (string)$has_issues,
        'GEDRAG.gedrag_check'       => (string)$new_check_status,
        'GEDRAG.gedrag_modified'    => date("Y-m-d H:i:s"),
        'GEDRAG.gedrag_toelichting' => (string)$val_toelichting,
        'GEDRAG.gedrag_notities'    => (string)$val_notities,
        'GEDRAG.gedrag_shortlist'   => $lists['gedrag_shortlist'], // Base-helpers zetten arrays om!
        'GEDRAG.gedrag_longlist'    => $lists['gedrag_longlist'],  // Base-helpers zetten arrays om!
    ];

    if ($context === 'direct' && !empty($data_to_inject)) {
        wachthond($extdebug, 1, "### UPDATE STRATEGIE: API CALL", "[FLOW]");
        $res_gedrag = base_api_wrapper('Contact', $contact_id, $data_to_inject, "GEDRAG_CONF");
    } else {
        wachthond($extdebug, 1, "### UPDATE STRATEGIE: RETOUR VOOR HOOK", "[FLOW]");
    }

    $processing_gedrag_configure = FALSE;
    return $data_to_inject;
}