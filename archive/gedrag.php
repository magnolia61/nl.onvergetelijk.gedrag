<?php

require_once 'gedrag.civix.php';

use CRM_Intake_ExtensionUtil as E;

function gedrag_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    $extdebug       = 3;  //  1 = basic // 2 = verbose // 3 = params / 4 = results
    $apidebug       = FALSE;

    $extwrite       = 1;
    $extgedrag      = 1;

    if ($op != 'create' && $op != 'edit') { //    did we just create or edit a custom object?
        wachthond($extdebug,4, "########################################################################");
        wachthond($extdebug,4, "### GEDRAG [PRE] EXIT: op != create OR op != edit", "(op: $op)");
        wachthond($extdebug,4, "########################################################################");
        return;
    }

    $today_datetime         = date("Y-m-d H:i:s");
    $today_datetime_past    = date('Y-m-d H:i:s', strtotime('-99 year', strtotime($today_datetime)) );
    wachthond($extdebug,4, 'today_datetime_past',       $today_datetime_past);

    $profilecontgedrag      = array(322);

    if (!in_array($groupID, $profilecontgedrag))  {

        wachthond($extdebug,4, "########################################################################");
        wachthond($extdebug,4, "### GEDRAG [PRE] EXIT: groupID ($groupID) not in allowed list (profilegedrag)", $profilegedrag);
        wachthond($extdebug,4, "########################################################################");
        return;
    }

    if (in_array($groupID, $profilecontgedrag)) {

        $contact_id = $entityID;

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### GEDRAG [PRE] 1.1 START RETRIEVE VALUES FROM PARAMS", "[groupID: $groupID / op: $op]");
        wachthond($extdebug,1, "########################################################################");

        wachthond($extdebug,3, "entityid",    $entityID);
        wachthond($extdebug,3, "params",      $params);

        // 1. Indexering van velden
        foreach($params as $i => $item) {
            if ($item['column_name'] == "gedrag_issues_1984")      { $key_gedrag_issues      = $i; }
            if ($item['column_name'] == "gedrag_shortlist_1985")   { $key_gedrag_shortlist   = $i; }
            if ($item['column_name'] == "gedrag_longlist_1986")    { $key_gedrag_longlist    = $i; }
            if ($item['column_name'] == "gedrag_toelichting_1987") { $key_gedrag_toelichting = $i; }
            if ($item['column_name'] == "gedrag_check_1989")       { $key_gedrag_check       = $i; }
            if ($item['column_name'] == "gedrag_notities_1988")    { $key_gedrag_notities    = $i; }
            if ($item['column_name'] == "gedrag_modified_2100")    { $key_gedrag_modified    = $i; }
        }

        // 2. Data ophalen & Initialiseren
        $val_gedrag_toelichting = $params[$key_gedrag_toelichting]['value'] ?? '';
        $val_gedrag_notities    = $params[$key_gedrag_notities]['value']    ?? '';
        
        // Ophalen Medische data (Toelichting + Medicatie) via APIv4
        $medische_tekst_totaal = '';
        try {
            $contact_medisch = civicrm_api4('Contact', 'get', [
                'select' => ['custom_1833', 'custom_1834'], // 1833=toelichting, 1834=medicatie
                'where' => [['id', '=', $entityID]],
            ])->first();
            
            $medische_tekst_totaal = ($contact_medisch['custom_1833'] ?? '') . ' ' . ($contact_medisch['custom_1834'] ?? '');
        } catch (\Exception $e) {
            wachthond($extdebug, 1, "GEDRAG FOUT: Kon medische data niet ophalen.");
        }

        // De "Uitsmijter Haystack": Alles inclusief medische data van de API
        $haystack_totaal = $val_gedrag_toelichting . ' ' . $val_gedrag_notities . ' ' . $medische_tekst_totaal;
        // De "Detectie Haystack": Alleen de velden uit het huidige Gedrag-formulier
        $haystack_gedrag_alleen = $val_gedrag_toelichting . ' ' . $val_gedrag_notities;

        // Arrays zuiveren
        $raw_shortlist = $params[$key_gedrag_shortlist]['value'] ?? '';
        // Forceer (string) voor explode
        $current_gedrag_shortlist = is_array($raw_shortlist) ? $raw_shortlist : array_filter(explode('', (string)$raw_shortlist));
        
        $raw_longlist = $params[$key_gedrag_longlist]['value'] ?? '';
        $current_gedrag_longlist  = is_array($raw_longlist) ? $raw_longlist : array_filter(explode('', (string)$raw_longlist));        

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 2.X COLLECTIEVE CONTROLE",                      "[SYNC]");
        wachthond($extdebug,2, "########################################################################");

        $new_gedrag_shortlist = (array)$current_gedrag_shortlist;
        $new_gedrag_longlist  = (array)$current_gedrag_longlist;

        // Definieer de checks: 'code' => [zoektermen]
        $gedrag_checks = [
            'bedplassen'    => ['bedplassen ', ' plassen', ' plast in bed ', 'zindelijk', 'zindelijkheid', ' plasluier ', ' luier'],
            'heimwee'       => ['heimwee'],
            'suicidaal'     => ['suicidaal','suïcidaal','suicide','suïcide','zelfdoding','zelfmoord'],
            'gezinshuis'    => ['gezinshuis'],
            'pleeggezin'    => ['pleeggezin','pleegzorg','pleegdochter','pleegzoon','pleegouders'],
            'hechting'      => ['hechting', 'hechtingsstoornis', 'hechtingstoornis', 'onveilig gehecht'],
            'autisme'       => ['autisme', ' ass ', 'autistisch', 'spectrumstoornis'],
            'adhd'          => ['adhd', 'a.d.h.d.', 'hyperactief', 'hyperactiviteit'],
            'add'           => [' add ', ' add.', 'ADD'],
            'pddnos'        => ['pddnos', 'pdd-nos', 'PDD-NOS'],
            'asperger'      => ['asperger'],
            'agressie'      => ['agressie', 'agressief', 'fysiek reageren', 'driftig', 'woede', ' odd ', 'grensoverschrijdend'],
            'slaapwandelen' => ['slaapwandelen', 'slaapwandelaar'],
            'hoogsensitief' => ['hoogsensitief', 'hoogsensitiviteit', 'hoogsensitieviteit', ' hsp ', 'hooggevoelig', 'hooggevoeligheid'],
            'paniek'        => ['paniekaanval', 'paniek aanval'],
            'dyslexie'      => ['dyslexie', 'dyslectisch', 'dyscalculie'],
            'tos'           => ['taalontwikkelingsstoornis', 'taalontwikkeling', ' tos '],
            'hoogbegaafd'   => ['hoogbegaafd', 'hoog begaafd'],
            'overgeslagen'  => ['overgeslagen'],
            'ptss'          => ['ptss','cptss','post traumatisch', 'trauma', 'trauma behandeling', 'emdr', 'flashbacks']
        ];

        foreach ($gedrag_checks as $value => $needles) {
            $is_in_shortlist = in_array($value, $new_gedrag_shortlist);
            $found_needle = str_contains_any_reporting($haystack_gedrag_alleen, $needles, false);

            if ($is_in_shortlist || $found_needle) {
                // Voeg toe aan longlist als het er nog niet in stond
                if (!in_array($value, $new_gedrag_longlist)) {
                    $new_gedrag_longlist[] = $value;
                    wachthond($extdebug, 1, "GEDRAG SYNC: [$value] -> Longlist. (Bron: " . ($found_needle ? "Tekst-match '$found_needle'" : "Vinkje") . ")");
                }
                
                // Shortlist auto-vink logica
                $allowed_on_shortlist = ['autisme', 'adhd', 'add', 'pddnos', 'asperger', 'hoogbegaafd'];
                if ($found_needle && in_array($value, $allowed_on_shortlist) && !$is_in_shortlist) {
                    $new_gedrag_shortlist[] = $value;
                    wachthond($extdebug, 1, "GEDRAG SYNC: [$value] -> Shortlist auto-vink via match: '$found_needle'");
                }
            } else {
                // VERWIJDER LOGICA (Jouw punt): Als er geen match is en geen shortlist vinkje, verwijder uit longlist
                $findKey = array_search($value, $new_gedrag_longlist);
                if ($findKey !== false) { 
                    unset($new_gedrag_longlist[$findKey]); 
                    wachthond($extdebug, 2, "GEDRAG SYNC: [$value] verwijderd (geen vinkje of tekst match)");
                }
            }
        }

        // Reset de array indexen na de unsets en zorg voor unieke waarden
        $new_gedrag_shortlist = array_values(array_unique($new_gedrag_shortlist));
        $new_gedrag_longlist  = array_values(array_unique($new_gedrag_longlist));

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 2.X MEDICATIE DETECTIE (AUTO-VINKJES LONGLIST)");
        wachthond($extdebug,2, "########################################################################");

        $medicatie_naar_gedrag = [
            'adhd'       => [
                // Stimulantia: bijna uitsluitend voor ADHD/ADD gebruikt
                'methylfenidaat', 'ritalin', 'concerta', 'equasym', 'medikinet', 
                'elvanse', 'tentin', 'dexamfetam', 'dexamfetamine', 'amfexa', 'kinecteen'
            ],
            'add'        => [
                // Specifieke non-stimulantia voor focus
                'strattera', 'stratera', 'atomoxetine'
            ],
            'autisme'    => [
                // Hoewel breed ingezet, vaak de enige medicatie bij ASS-prikkelgevoeligheid
                'risperidon', 'risperdal', 'pipamperon', 'dipiperon'
            ],
            'bedplassen' => [
                // Zeer specifieke indicatie
                'desmopressine', 'minrin'
            ],
            'depressie'  => [
                // Brede groep, maar duidt zonder twijfel op stemming/angst (niet op agressie)
                'amitriptyline', 'bupropion', 'citalopram', 'clomipramine', 
                'duloxetine', 'escitalopram', 'fluoxetine', 'fluvoxamine', 
                'nortriptyline', 'paroxetine', 'sertraline', 'venlafaxine', 
                'wellbutrin', 'prozac', 'lithium'
            ],
            'ptss'       => [
                // Prazosine wordt in de psychiatrie specifiek voor PTSS-nachtmerries gegeven
                'prazosine'
            ]
        ];

        foreach ($medicatie_naar_gedrag as $diagnose => $medicijnen) {  
            $found_med = str_contains_any_reporting($haystack_totaal, $medicijnen, false);
            
            if ($found_med) {
                // Voeg toe aan Longlist
                if (!in_array($diagnose, $new_gedrag_longlist)) {
                    $new_gedrag_longlist[] = $diagnose;
                    wachthond($extdebug, 1, "GEDRAG MEDICATIE: [$diagnose] in Longlist via match: '$found_med'");
                }

                // Voeg toe aan Shortlist (indien van toepassing)
                $allowed_on_shortlist = ['adhd', 'add', 'autisme']; 
                if (in_array($diagnose, $allowed_on_shortlist) && !in_array($diagnose, $new_gedrag_shortlist)) {
                    $new_gedrag_shortlist[] = $diagnose;
                    wachthond($extdebug, 1, "GEDRAG MEDICATIE: [$diagnose] ook in Shortlist aangevinkt.");
                }
        } else {
            // VERWIJDER LOGICA: 
            // Alleen verwijderen als het NIET in de shortlist staat EN NIET in de tekst is gevonden
            $found_in_tekst = str_contains_any_reporting($haystack_gedrag_alleen, $gedrag_checks[$diagnose] ?? [], false);

            if (!in_array($diagnose, $new_gedrag_shortlist) && !$found_in_tekst) {
                $findKey = array_search($diagnose, $new_gedrag_longlist);
                if ($findKey !== false) { 
                    unset($new_gedrag_longlist[$findKey]); 
                    wachthond($extdebug, 2, "GEDRAG MEDICATIE: [$diagnose] verwijderd (geen medicijn match én geen tekst match)");
                }
            }
        }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 3.X AUTOMATISCHE STATUS 'CHECK' BEPALEN",     "[CHECKS]");
        wachthond($extdebug,2, "########################################################################");

        $extra_check_diagnoses = ['ptss', 'agressie', 'hechting', 'suicidaal', 'paniek', 'tos', 'autisme'];
        
        $keywords_status_1 = ['amitriptyline','antidepressiva','antisociaal','bupropion','carbamazepine','clomipramine','depressie','depressief','depressiva','depressiviteit','dexamfetam','driftig','duloxetine','escitalopram','fluoxetine','fluvoxamine','hechtingsproblematiek','lithium','mishandeling','nortriptyline','oxazepam','paroxetine','prazepam','pregabaline','prozac','sertraline','valproaat','venlafaxine','methylfenidaat','concerta','faalangst','psycholoog','jeugdzorg', 'persoonlijkheidsstoornis'];

        $keywords_status_2 = ['afkick','aggresief','agresie','agressie','alprazolam','angststoornis','antipsychotica','antipsychotische','aripiprazol','campral','clozapine','cyproteronactetaat','decapeptyl','esketamine','lamotrigine','lorazepam','manipuleren','misbruik','mishandeld','normoverschrijdend','oestradiol','pamerolin','paniek','paniekaanval','psychose','psychotisch','quetiapine','selincro','spironolacton','verslaving','woede','xanax','automutilatie','uithuisplaatsing','veilig thuis','risperidon','pipamperon'];

        $strict_status_1 = ['liegen', 'onveilig', 'betast', 'sint-janskruid', 'tics', 'poh'];
        $strict_status_2 = ['odd', 'benzo', 'cannabis', 'vth', 'ass', 'hsp'];

        $new_check_status = "0"; 

        // PRIORITEIT 1: Kritieke diagnoses in de longlist
        $matching_extra = array_intersect($new_gedrag_longlist, $extra_check_diagnoses);
        if (!empty($matching_extra)) {
            $new_check_status = "2";
            wachthond($extdebug, 1, "GEDRAG STATUS: Status 2 via longlist: " . implode(', ', $matching_extra));
        } 
        // PRIORITEIT 2: Losse keywords in tekst
        elseif ($found = str_contains_any_reporting($haystack_totaal, $keywords_status_2, false)) {
            $new_check_status = "2";
            wachthond($extdebug, 1, "GEDRAG STATUS: Status 2 via keyword '$found'");
        } elseif ($found = str_contains_word_reporting($haystack_totaal, $strict_status_2)) {
            $new_check_status = "2";
            wachthond($extdebug, 1, "GEDRAG STATUS: Status 2 via strict word '$found'");
        } elseif ($found = str_contains_any_reporting($haystack_totaal, $keywords_status_1, false)) {
            $new_check_status = "1";
            wachthond($extdebug, 1, "GEDRAG STATUS: Status 1 via keyword '$found'");
        } elseif ($found = str_contains_word_reporting($haystack_totaal, $strict_status_1)) {
            $new_check_status = "1";
            wachthond($extdebug, 1, "GEDRAG STATUS: Status 1 via strict word '$found'");
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 3.0 SCHRIJF NAAR DB (MET APIv4 VALIDATIE)");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 3.1 LONGLIST VALIDATIE EN OPSLAG");
        wachthond($extdebug,2, "########################################################################");

        if (is_numeric($key_gedrag_longlist)) {
            $customField = civicrm_api4('CustomField', 'get', [
                'select' => ['option_group_id'],
                'where' => [['name', 'LIKE', 'gedrag_longlist%'], ['custom_group_id', '=', 322]],
            ])->first();

            $optionGroupId = $customField['option_group_id'] ?? NULL;

            if ($optionGroupId) {
                $validOptions = civicrm_api4('OptionValue', 'get', [
                    'select' => ['value'],
                    'where' => [['option_group_id', '=', $optionGroupId]],
                    'limit' => 0,
                ])->column('value');

                $valid_selected = array_intersect((array)$new_gedrag_longlist, (array)$validOptions);            
                $diff           = array_diff((array)$new_gedrag_longlist, (array)$validOptions);
                if (!empty($diff)) {
                    wachthond($extdebug, 1, "GEDRAG VALIDATIE: Waarden genegeerd: " . implode(', ', $diff));
                }

                $params[$key_gedrag_longlist]['value'] = format_civicrm_string($valid_selected);
            }
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 3.2 SHORTIST VALIDATIE EN OPSLAG");
        wachthond($extdebug,2, "########################################################################");

        if (is_numeric($key_gedrag_shortlist)) {
            // format_civicrm_string regelt intern de array-check
            $params[$key_gedrag_shortlist]['value'] = format_civicrm_string($new_gedrag_shortlist);
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 3.3 HOOFDVINKJE GEDRAG ISSUES");
        wachthond($extdebug,2, "########################################################################");

        if (is_numeric($key_gedrag_issues)) {
            // Check alle drie de bronnen: shortlist, longlist en de tekstuele toelichting
            $has_issues = (!empty($new_gedrag_shortlist) || !empty($new_gedrag_longlist) || !empty(trim($val_gedrag_toelichting))) ? 1 : 0;

            $params[$key_gedrag_issues]['value'] = $has_issues;
            wachthond($extdebug, 2, "GEDRAG ISSUES: Vinkje gezet op $has_issues (Shortlist: ".count($new_gedrag_shortlist)." / Longlist: ".count($new_gedrag_longlist).")");
        }        

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 3.4 AUTOMATISCHE CHECK STATUS");
        wachthond($extdebug,2, "########################################################################");

        if (is_numeric($key_gedrag_check) && $extwrite == 1) {
            // Forceer naar string (voor radio) of int (voor select) afhankelijk van je veldtype
            // In CiviCRM zijn optie-waarden meestal strings, maar casting naar string is hier safe
            $params[$key_gedrag_check]['value'] = (string)$new_check_status; 
            wachthond($extdebug, 1, "GEDRAG CHECK: Status gezet op $new_check_status");
        }

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 3.5 MODIFIED DATUM");
        wachthond($extdebug,2, "########################################################################");

        if ($extwrite == 1) {
            $db_timestamp = date("YmdHis");
            
            if ($key_gedrag_modified !== NULL) {
                // Het veld zit al in de params (profiel), gewoon de waarde updaten
                $params[$key_gedrag_modified]['value'] = $db_timestamp;
                wachthond($extdebug, 1, "GEDRAG: Modified datum geüpdatet in params.");
            } else {
                // Het veld ontbreekt in het profiel. We voegen het toe MET de vereiste metadata.
                // Voor een Datum/Tijd veld is het type 'Date' of 'Timestamp'. 
                // In een customPre hook is 'String' of 'Date' meestal veilig.
                $params[] = [
                    'column_name' => 'gedrag_modified_2100',
                    'value'       => $db_timestamp,
                    'type'        => 'Date', // DIT VOORKOMT DE FATAL ERROR
                    'custom_id'   => 2100,
                    'entity_id'   => $entityID
                ];
                wachthond($extdebug, 1, "GEDRAG: Modified datum geforceerd toegevoegd met type metadata.");
            }
        }

        wachthond($extdebug, 1, "########################################################################");
        wachthond($extdebug, 1, "### GEDRAG DEBUG SUMMARY VOOR ENTITY: $entityID",               "[DEBUG]");
        wachthond($extdebug, 1, "########################################################################");        

        // --- SWEEP VOOR DRUPAL ENTITY CONTROLLER (UNIX TIMESTAMPS) ---
        drupal_timestamp_sweep($params);

        // --- WATCHDOG DEBUG OVERZICHT ---
        $debug_summary = [
            'Contact ID'     => $entityID,
            'Gedrag Issues'  => $has_issues,
            'Shortlist New'  => $new_gedrag_shortlist,
            'Longlist New'   => $new_gedrag_longlist,
            'Check Status'   => $new_check_status,
            'Modified'       => $db_timestamp,
        ];

        wachthond($extdebug,2, "$debug_summary",    $debug_summary);

        wachthond($extdebug,2, "########################################################################");
        wachthond($extdebug,2, "### GEDRAG [PRE] 4.6 GEEF DE DEFINITIEVE WAARDEN WEER",        "[PARAMS]");
        wachthond($extdebug,2, "########################################################################");

        wachthond($extdebug,2, "params",            $params);

        wachthond($extdebug,1, "########################################################################");
        wachthond($extdebug,1, "### GEDRAG [PRE] EINDE PROFILE VOOR $entityID", "[groupID: $groupID / op: $op]");
        wachthond($extdebug,1, "########################################################################");

    }

}

