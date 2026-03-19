<?php

require_once 'gedrag.civix.php';
use CRM_Intake_ExtensionUtil as E;

/**
 * Hook Pre: De "Verkeersregelaar".
 * VERSIE 4.1: HYBRIDE + ORIGINELE BACKUP.
 * * Ondersteunt zowel Backend (Namen) als Profielen (ID's + Value objecten).
 * * Slaat de originele input op in $params_org voor debugging.
 */
function gedrag_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    $profilecontgedrag = [322]; 
    $extdebug          = 0; 

    // CHECK: Is dit de juiste groep en actie?
    if (!in_array($groupID, $profilecontgedrag) || ($op != 'create' && $op != 'edit')) {
        return;
    }

    // --- STAP 0: VEILIGSTELLEN ORIGINELE DATA ---
    // We maken direct een kopie van de input voordat we iets aanraken.
    $params_org = $params;

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG PRE - START HOOK VOOR CONTACT: " . $entityID,   "[HOOK-START]");
    
    // LOG: We tonen de originele, onaangetaste input
    wachthond($extdebug, 3, "DEBUG: OORSPRONKELIJKE PARAMS (\$params_org)", $params_org);

    // -------------------------------------------------------------------------
    // DEFINITIE: MAPPING OP ID (Voor Profielen)
    // -------------------------------------------------------------------------
    $id_map = [
        1984 => 'GEDRAG.gedrag_issues',
        1985 => 'GEDRAG.gedrag_shortlist',
        1986 => 'GEDRAG.gedrag_longlist',
        1987 => 'GEDRAG.gedrag_toelichting',
        1988 => 'GEDRAG.gedrag_notities',
        1989 => 'GEDRAG.gedrag_check',
        1994 => 'GEDRAG.gedrag_modified',
    ];

    // -------------------------------------------------------------------------
    // DEFINITIE: MAPPING OP NAAM (Voor Backend/API)
    // -------------------------------------------------------------------------
    $name_map = [
        'gedrag_issues_1984'      => 'GEDRAG.gedrag_issues',
        'gedrag_shortlist_1985'   => 'GEDRAG.gedrag_shortlist',
        'gedrag_longlist_1986'    => 'GEDRAG.gedrag_longlist',
        'gedrag_toelichting_1987' => 'GEDRAG.gedrag_toelichting',
        'gedrag_notities_1988'    => 'GEDRAG.gedrag_notities',
        'gedrag_check_1989'       => 'GEDRAG.gedrag_check',
        'gedrag_modified_1994'    => 'GEDRAG.gedrag_modified',
    ];

    // -------------------------------------------------------------------------
    // DETECTIE: IN WELKE MODUS ZIJN WE?
    // -------------------------------------------------------------------------
    // Profiel modus herkennen we: Index 0 is een array én heeft een 'custom_field_id'.
    // We gebruiken hier $params_org voor de detectie (is veilig).
    $is_profile_mode = (isset($params_org[0]) && is_array($params_org[0]) && isset($params_org[0]['custom_field_id']));

    if ($is_profile_mode) {
        // =====================================================================
        // SCENARIO A: PROFIEL MODUS (De "Moeilijke" Structuur)
        // =====================================================================
        // Hierin zit de data verstopt in objecten: [0] => ['custom_field_id'=>1984, 'value'=>'...']
        wachthond($extdebug, 3, "MODUS: Profiel Structuur gedetecteerd (Werken met ID's).");

        // STAP 1: INPUT VERZAMELEN (Op basis van ID)
        $motor_params = [];
        foreach ($params_org as $index => $field_data) {
            $fid = $field_data['custom_field_id'] ?? 0;
            if (isset($id_map[$fid])) {
                $api_key = $id_map[$fid];
                // In deze modus zit de waarde altijd in de key 'value'
                $motor_params[$api_key] = $field_data['value'] ?? '';
            }
        }
        wachthond($extdebug, 3, "STAP 1: Data verzameld voor motor", $motor_params);

        // STAP 2: DE MOTOR (Rekenen)
        $results = gedrag_civicrm_configure($entityID, $motor_params, 'hook');

        // STAP 3: TERUGSCHRIJVEN (Injecteren in 'value')
        // We lopen door de $params (reference!) heen en updaten de 'value' waar nodig.
        foreach ($params as $index => &$field_data) {
            $fid = $field_data['custom_field_id'] ?? 0;
            
            if (isset($id_map[$fid])) {
                $api_key = $id_map[$fid];
                
                // Als de motor een waarde heeft voor dit ID...
                if (isset($results[$api_key])) {
                    $nieuwe_waarde = $results[$api_key];
                    $oude_waarde   = $field_data['value'] ?? '[LEEG]';

                    // Loggen als we iets veranderen
                    if ($oude_waarde != $nieuwe_waarde) {
                        wachthond($extdebug, 4, "--> UPDATE ID $fid ($api_key): 'value' wijzigt van '$oude_waarde' naar '$nieuwe_waarde'");
                    }
                    
                    // CRUCIAAL: Update de 'value'. Dit is wat CiviCRM opslaat in deze modus.
                    $field_data['value'] = $nieuwe_waarde;
                }
            }
        }

    } else {
        // =====================================================================
        // SCENARIO B: STANDAARD MODUS (De "Makkelijke" Structuur)
        // =====================================================================
        // Hierin is het gewoon: ['gedrag_issues_1984' => '1']
        wachthond($extdebug, 3, "MODUS: Standaard Structuur gedetecteerd (Werken met Namen).");
        
        // Hulpfunctie voor platte records
        $verwerk_record = function(&$record) use ($entityID, $extdebug, $name_map) {
            // 1. Verzamelen
            $motor_params = [];
            foreach ($record as $key => $val) {
                if (isset($name_map[$key])) {
                    $motor_params[$name_map[$key]] = $val;
                }
            }
            wachthond($extdebug, 3, "STAP 1: Data verzameld", $motor_params);

            // 2. Rekenen
            $results = gedrag_civicrm_configure($entityID, $motor_params, 'hook');
            
            // 3. Terugschrijven
            foreach ($results as $api_key => $calculated_val) {
                $keys = array_keys($name_map, $api_key);
                foreach ($keys as $map_key) {
                     if (isset($record[$map_key]) && $record[$map_key] != $calculated_val) {
                         wachthond($extdebug, 4, "--> UPDATE: $map_key naar '$calculated_val'");
                     }
                     $record[$map_key] = $calculated_val;
                }
            }
        };

        // Routering voor plat of genummerd (Multi-record detectie)
        $first_key = array_key_first($params);
        $is_multi_record = is_int($first_key) && is_array($params[$first_key]);

        if ($is_multi_record) {
             foreach ($params as &$sub_record) {
                 if (is_array($sub_record)) $verwerk_record($sub_record);
             }
        } else {
             $verwerk_record($params);
        }
    }

    // LOG: Het eindresultaat dat naar de database gaat
    wachthond($extdebug, 3, 'DEBUG: DEFINITIEVE PARAMS (OUTPUT NA MODIFICATIE)', $params);

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG PRE - EINDE HOOK VOOR CONTACT: " . $entityID,   "[HOOK-EINDE]");
    wachthond($extdebug, 3, "########################################################################");
}
    
/**
 * Centrale verwerkingsfunctie (De Motor) voor Gedrag.
 * * DOEL:
 * Deze functie bepaalt de status van het gedrags-dossier.
 * 1. Het combineert bestaande data (DB) met nieuwe invoer om dataverlies te voorkomen.
 * 2. Het scant teksten en medicatie (ook uit Medisch!) op trefwoorden.
 * 3. Het berekent de vlaggetjes: Issues (Ja/Nee) en Check (Groen/Oranje/Rood).
 */
function gedrag_civicrm_configure($entityID = NULL, array $params = [], $op = 'direct', array $mapping = []): array {

    // --- STAP 0.1: RECURSIE STOP ---
    // Voorkomt dat de functie in een oneindige loop raakt als hij zichzelf per ongeluk aanroept.
    static $processing_gedrag = [];
    
    if (!empty($entityID)) {
        if (isset($processing_gedrag[$entityID])) return $params;
        $processing_gedrag[$entityID] = true;
    }

    $extdebug    = 0; // Log-niveau (3=standaard, 5=detail)
    $displayname = "Onbekend";

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 1.0. START CONFIGURATIE (ID: $entityID)",             "[START]");
    wachthond($extdebug, 3, "########################################################################");

    if (!empty($entityID)) {
        
        // --- OPTIMALISATIE: STATIC CACHE ---
        // Slaat database resultaten op in het geheugen voor deze sessie.
        static $contact_cache_gedrag = [];
        $result_contact_get = null;

        if (isset($contact_cache_gedrag[$entityID])) {
            $result_contact_get = $contact_cache_gedrag[$entityID];
            wachthond($extdebug, 3, "GEDRAG: Data uit CACHE geladen (DB gespaard)");
            
        } else {
            
            // 1. SELECTIE DEFINIEREN
            // We halen altijd de context-velden op (Medisch & Curriculum) voor de analyse.
            $select_fields = [
                'display_name', 
                'Curriculum.Laatste_keer',      // Context
                'MEDISCH.medisch_toelichting',  // Context: we lezen mee met medisch
                'MEDISCH.medisch_medicatie'     // Context: we scannen medicatie
            ];

            // 2. [FIX DATAVERLIES] DIRECT CALL & HOOK BESCHERMING
            // We voegen hier 'hook' toe. Dit zorgt ervoor dat de motor ook bij een profiel-opslag
            // even in de database kijkt om de Medische context en Curriculum info op te halen.
            // Zo kunnen we 'Ritalin' (uit DB) koppelen aan 'ADHD' (in formulier).
            if ($op == 'direct' || $op == 'hook') {
                $select_fields = array_merge($select_fields, [
                    'GEDRAG.gedrag_issues',
                    'GEDRAG.gedrag_toelichting', 
                    'GEDRAG.gedrag_notities',    
                    'GEDRAG.gedrag_check',
                    'GEDRAG.gedrag_modified',
                    'GEDRAG.gedrag_shortlist',   
                    'GEDRAG.gedrag_longlist'
                ]);
            }

            // 3. API AANROEP
            try {
                $result_contact_get = civicrm_api4('Contact', 'get', [
                    'checkPermissions' => FALSE,
                    'select'           => $select_fields,
                    'where'            => [['id', '=', $entityID]],
                    'limit'            => 1,
                ])->first();
            } catch (\Exception $e) {
                wachthond(1, 3, "GEDRAG FETCH ERROR: " . $e->getMessage());
            }

            // 4. CACHE VULLEN
            if ($result_contact_get) {
                $contact_cache_gedrag[$entityID] = $result_contact_get;
            }
        }
        
        // --- VERWERKING ---
        if ($result_contact_get) {
            $displayname = $result_contact_get['display_name'] ?? "Onbekend";
            
            // [FIX SLIMME MERGE]
            // Ook bij een hook willen we de bestaande data uit de database combineren met de nieuwe input.
            // Dit voorkomt dat een veld dat NIET op het formulier staat, per ongeluk wordt leeggemaakt.
            if ($op == 'direct' || $op == 'hook') {
                $input_params = array_filter($params, function($v) { 
                    return $v !== '' && $v !== null; 
                });
                
                $params = array_merge($result_contact_get, $input_params);
                wachthond($extdebug, 5, "GEDRAG MERGE COMPLETE voor $displayname");
            }
        }
    }

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 1.1. INITIALISATIE LIJSTEN",                          "[CURRENT]");
    wachthond($extdebug, 3, "########################################################################");

    // --- STAP 1: LIJSTEN INITIALISEREN ---
    $init_keys = [
        'gedrag_shortlist' => 'GEDRAG.gedrag_shortlist',
        'gedrag_longlist'  => 'GEDRAG.gedrag_longlist',
    ];

    $lists = [];
    foreach ($init_keys as $key => $api_name) {
        $raw_input = $params[$api_name] ?? '';
        
        // [FIX] ARRAY VS STRING
        // API4 geeft arrays terug, formulieren strings. We moeten beide aan kunnen.
        if (is_array($raw_input)) {
             $as_array = $raw_input;
        } else {
             $clean_string = format_civicrm_smart($raw_input, $api_name);
             $as_array = explode(CRM_Core_DAO::VALUE_SEPARATOR, trim($clean_string, CRM_Core_DAO::VALUE_SEPARATOR));
        }
        
        // Filter lege waarden eruit
        $lists[$key] = (array) array_values(array_filter($as_array, function($v) {
            return !empty($v) && is_string($v) && trim($v) !== '' && strtolower($v) !== 'array';
        }));
    }

    // --- STAP 2: VARIABELEN TOEWIJZEN (VEILIG) ---

    // Helper functie: Pakt Input, anders DB, anders leeg string.
    $pak_waarde = fn($key) => !empty($params[$key]) ? $params[$key] : ($result_contact_get[$key] ?? '');

    // Eigen Gedrag velden
    $val_toelichting     = $pak_waarde('GEDRAG.gedrag_toelichting');
    $val_notities        = $pak_waarde('GEDRAG.gedrag_notities');

    // Medische context (Read-only voor analyse)
    $val_med_toelichting = $pak_waarde('MEDISCH.medisch_toelichting');
    $val_med_medicatie   = $pak_waarde('MEDISCH.medisch_medicatie');
    $val_curriculum      = $pak_waarde('Curriculum.Laatste_keer');

    // De Haystack: We plakken alles aan elkaar om in één keer te kunnen zoeken.
    // Dit zorgt ervoor dat we ook gedragsproblemen vinden die per ongeluk bij Medisch zijn ingevuld.
    $haystack_gedrag = $val_toelichting . ' ' . $val_notities . ' ' . $val_med_toelichting . ' ' . $val_med_medicatie;
    
    wachthond($extdebug, 3, "GEDRAG HAYSTACK LEN: " . strlen($haystack_gedrag));

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 2.0. OPSCHONEN NEGATIEVE TERMEN",                 "[CLEANUP]");
    wachthond($extdebug, 3, "########################################################################");

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
            $val = ''; $params[$f_name] = ''; 
        }
    }
    unset($val); // Veiligheid: referentie verbreken

    wachthond($extdebug, 3, "########################################################################");
    wachthond($extdebug, 3, "### GEDRAG 3.0. ANALYSE GEDRAGSCHECKS (DETECTIE & SYNC)",    "[SYNC]");
    wachthond($extdebug, 3, "########################################################################");

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
        'bedplassen'        => ['bedplassen', 'laten plassen', 'in bed plassen', ' plast in bed ', 'zindelijk', 'zindelijkheid', ' plasluier ', ' luier'],
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
    
    // Finale Parameters voor de Database
    $params_values = [
        'GEDRAG.gedrag_issues'      => (string)$has_issues,
        'GEDRAG.gedrag_check'       => (string)$new_check_status,
        'GEDRAG.gedrag_modified'    => date("Y-m-d H:i:s"),
        'GEDRAG.gedrag_toelichting' => (string)$val_toelichting,
        'GEDRAG.gedrag_notities'    => (string)$val_notities,
        'GEDRAG.gedrag_shortlist'   => (string)format_civicrm_smart($lists['gedrag_shortlist'], 'GEDRAG.gedrag_shortlist'),
        'GEDRAG.gedrag_longlist'    => (string)format_civicrm_smart($lists['gedrag_longlist'],  'GEDRAG.gedrag_longlist'),
    ];

    // Laatste veiligheidscheck: geen arrays naar de DB sturen!
    foreach ($params_values as $key => $val) {
        if (is_array($val)) {
            wachthond(3, 3, "CRITICAL ALERT: Veld $key was een ARRAY. Geforceerd naar leeg.");
            $params_values[$key] = "";
        }
    }
    
    wachthond($extdebug, 3, 'gedrag_params_values', $params_values);

    // DIRECT DATABASE UPDATE (Alleen bij directe aanroep vanuit core.php)
    if ($op == 'direct' && !empty($entityID)) {
        $params_update_gedrag = [
            'checkPermissions' => FALSE,
            'where'            => [['id', '=', (int)$entityID]],
            'values'           => $params_values, 
        ];
        
        wachthond($extdebug, 7, 'params_update_gedrag', $params_update_gedrag);
        try {
            civicrm_api4('Contact', 'update', $params_update_gedrag);
        } catch (\Exception $e) {
            wachthond(1, 3, "API Update Error: " . $e->getMessage());
        }
    }

    // Resultaten samenvoegen met input en returnen
    $params = array_merge($params, $params_values);
    drupal_timestamp_sweep($params);

    // Recursie vrijgeven
    if (!empty($entityID)) {
        unset($processing_gedrag[$entityID]);
    }
    return $params;
}