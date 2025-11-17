<?php
// --- TEIL 1: PHP-BACKEND-LOGIK (LÄUFT AUF DEM SERVER) ---
  
  /**
  * Dies ist ein einfacher "Router". Er prüft, ob eine Aktion (z.B. 'save')
* per POST-Request an diese Datei gesendet wurde.
*/
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save') {
    
    // 1. Empfange die JSON-Daten, die das JavaScript-Frontend gesendet hat
    $data = json_decode(file_get_contents('php://input'), true);
    
    // 2. Führe die sichere Server-Funktion aus
    try {
      $result = saveDataToFirebase($data);
      
      // 3. Sende eine Erfolgs-Antwort an das JavaScript-Frontend
      header('Content-Type: application/json');
      echo json_encode([
        'status' => 'success',
        'message' => 'Daten erfolgreich auf dem Server gespeichert.',
        'docId' => $result
      ]);
      
    } catch (Exception $e) {
      // 4. Sende eine Fehler-Antwort
      header('Content-Type: application/json', true, 500); // HTTP 500
      echo json_encode([
        'status' => 'error',
        'message' => 'Server-Fehler: ' . $e->getMessage()
      ]);
    }
    
    // Wichtig: Stoppt das Skript, damit nicht der HTML-Teil gesendet wird
    exit;
  }


/**
  * SIMULIERTE FUNKTION ZUM SPEICHERN IN FIREBASE (SERVER-SIDE!)
*
  * In einer echten PHP-Framework-App (z.B. Laravel) würden Sie hier
* die 'firebase-php/firebase-admin-sdk' Bibliothek verwenden (via Composer).
*
  * WICHTIG: Der "Service Account" (geheime .json-Datei) liegt
* sicher auf Ihrem Server und wird *niemals* an den Browser gesendet.
*/
  function saveDataToFirebase($data) {
    // --- HIER IST DER SICHERE BEREICH ---
      
      /*
      // Beispiel (Pseudo-Code, wie es mit dem Admin SDK aussehen würde):
      
      // 1. Firebase Admin SDK initialisieren (nur einmal pro App-Start)
    // $serviceAccount = \Kreait\Firebase\ServiceAccount::fromJsonFile('/pfad/zu/ihrem/geheimen/serviceAccount.json');
    // $firebase = (new \Kreait\Firebase\Factory)
    //     ->withServiceAccount($serviceAccount)
    //     ->create();
    
    // $firestore = $firebase->firestore();
    // $db = $firestore->database();
    
    // 2. Den Pfad definieren (z.B. aus einer .env-Datei)
    // $appId = "default-agff-app-public"; 
    // $collectionPath = "artifacts/{$appId}/public/data/futterproben";
    
    // 3. Daten hinzufügen (der Server macht dies, nicht der Client)
    // $newDoc = $db->collection($collectionPath)->add($data);
    
    // return $newDoc->id(); // Gibt die neue Dokumenten-ID zurück
    */
      
      // Nur zur Simulation:
      // Wir loggen die empfangenen Daten in eine lokale Datei (statt Firebase)
    // um zu beweisen, dass sie auf dem Server angekommen sind.
    file_put_contents('server_log.txt', "SPEICHERE: " . json_encode($data) . "\n", FILE_APPEND);
    
    // Simuliert eine erfolgreiche Speicherung
    if (empty($data['probeNr'])) {
      throw new Exception("Proben-Nr. fehlt.");
    }
    
    return "simulierte-doc-id-" . time();
  }


/**
  * LÄDT UND PARST DIE FUTTERWERTE-CSV (SERVER-SIDE)
* Diese Funktion ersetzt die JavaScript 'loadAndParseCSV'-Funktion.
*/
  function loadAndParseCSV() {
    $csvFile = 'futterwerte.csv'; // Annahme: CSV ist im selben Ordner
    if (!file_exists($csvFile)) {
      return null; // Fehler, Datei nicht gefunden
    }
    
    $futterWerte = ['erster' => [], 'folge' => []];
    $headers = [];
    $colIdx = [];
    
    // 'r' = read (lesen)
    if (($handle = fopen($csvFile, 'r')) !== FALSE) {
      
      // 1. Header-Zeile lesen
      if (($data = fgetcsv($handle, 1000, ';')) !== FALSE) {
        // Konvertiert von ISO-8859-1 (oder was auch immer Ihr CSV ist) zu UTF-8
        $headers = array_map(function($h) {
          return trim(mb_convert_encoding($h, 'UTF-8', 'ISO-8859-1')); // Passen Sie 'ISO-8859-1' ggf. an
        }, $data);
        
        // Spaltenindizes robust finden
        $colIdx['futterart'] = array_search_key_custom($headers, 'futterart');
        $colIdx['bestand'] = array_search_key_custom($headers, 'bestand');
        $colIdx['aufwuchs'] = array_search_key_custom($headers, 'aufwuchs');
        $colIdx['stadium'] = array_search_key_custom($headers, 'stadium');
        $colIdx['nel'] = array_search_key_custom($headers, 'nel');
        $colIdx['nev'] = array_search_key_custom($headers, 'nev');
        $colIdx['apde'] = array_search_key_custom($headers, 'apde');
        $colIdx['apdn'] = array_search_key_custom($headers, 'apdn');
        // ... (alle anderen Spalten)
      }
      
      // 2. Datenzeilen lesen
      while (($data = fgetcsv($handle, 1000, ';')) !== FALSE) {
        
        // Konvertierung für Datenzeilen
        $values = array_map(function($v) {
          return trim(mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1'));
        }, $data);
        
        if (count($values) < count($headers)) continue; // Zeile überspringen
        
        $futterartDE = strtolower($values[$colIdx['futterart']]);
        $aufwuchsDE = strtolower($values[$colIdx['aufwuchs']]);
        $bestand = strtoupper($values[$colIdx['bestand']]);
        $stadium = $values[$colIdx['stadium']];
        
        // 1. Aufwuchs-Schlüssel
        $aufwuchs = (strpos($aufwuchsDE, 'erster') !== false || strpos($aufwuchsDE, '1') === 0) ? 'erster' : 'folge';
        
        // 2. Konservierungs-Schlüssel (mit 'strpos' statt '===')
        $konservierung = '';
        if (strpos($futterartDE, 'grünfutter') === 0) $konservierung = 'greenfeed';
        elseif (strpos($futterartDE, 'silage') === 0) $konservierung = 'silage';
        elseif (strpos($futterartDE, 'dürrfutter') === 0) $konservierung = 'hay';
        else continue;
        
        if (empty($bestand) || empty($stadium)) continue;
        
        // 4. Datenobjekt erstellen
        $futterWerte[$aufwuchs][$konservierung][$bestand][$stadium] = [
          'nel' => getValPhp($values, $colIdx, 'nel'),
          'nev' => getValPhp($values, $colIdx, 'nev'),
          'apde' => getValPhp($values, $colIdx, 'apde'),
          'apdn' => getValPhp($values, $colIdx, 'apdn'),
          // ... (alle anderen Werte)
        ];
      }
      fclose($handle);
    }
    return $futterWerte;
  }

// Hilfsfunktion: findet Spaltenindex (wie 'nel') auch wenn Header 'nel [MJ]' heisst
function array_search_key_custom($headers, $key) {
  foreach ($headers as $index => $header) {
    if (strpos(strtolower($header), $key) === 0) {
      return $index;
    }
  }
  return -1;
}

// Hilfsfunktion: Parst Kommazahlen sicher
function getValPhp($values, $colIdx, $key) {
  $idx = $colIdx[$key];
  if ($idx === -1 || !isset($values[$idx]) || $values[$idx] === '') return 0;
  return floatval(str_replace(',', '.', $values[$idx])) ?: 0;
}


// --- HAUPTAUSFÜHRUNG (WIRD BEI SEITENAUFRUF AUSGEFÜHRT) ---
  
  // 1. Lade die CSV-Daten auf dem Server
$futterWerte = loadAndParseCSV();
  
  // 2. Konvertiere die Daten in JSON, um sie sicher ins JavaScript einzubetten
  $futterWerteJson = json_encode($futterWerte);
  
  if ($futterWerteJson === false) {
    // Falls das JSON-Encoding fehlschlägt (z.B. wegen UTF-8 Problemen)
    $futterWerteJson = 'null';
    error_log("JSON-Encoding der Futterwerte fehlgeschlagen: " . json_last_error_msg());
  }
  
  ?>
    
    <!-- --- TEIL 2: HTML/JS-FRONTEND (LÄUFT IM BROWSER) --- -->
    <!-- (Dies ist Ihr Code aus index.html, aber angepasst) -->
    
    <!DOCTYPE html>
    <html lang="de">
      <head>
      <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>AGFF Futterbewertung (PHP-Backend Version)</title>
          <script src="https://cdn.tailwindcss.com"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
              
              <script type="module">
                // --- ENTFERNT ---
                // Die Firebase SDKs werden im Frontend NICHT MEHR importiert.
              // import { initializeApp } ...
              // import { getFirestore } ...
              
              // --- GLOBALE VARIABLEN ---
                let futterWerte = {}; // Wird jetzt vom PHP-Server gefüllt
                let aktuellesStadiumLabel = "Nicht bestimmt";
                // const PB_NEL, PB_PROTEIN bleiben gleich...
                const PB_NEL = 3.14; 
                const PB_PROTEIN = 50; 
                
                // --- DATENLADEN & INIT ---
                  document.addEventListener('DOMContentLoaded', () => {
                    showLoading(true, "Lade Futterwerte vom Server...");
                    
                    // ... (localStorage-Code bleibt gleich) ...
                    try {
                      const gespeicherterName = localStorage.getItem('agffBeurteilerName');
                      if (gespeicherterName) {
                        document.getElementById('personName').value = gespeicherterName;
                      }
                      const gespeicherteProbeNr = localStorage.getItem('agffProbenNr');
                      if (gespeicherteProbeNr) {
                        document.getElementById('probeNr').value = gespeicherteProbeNr;
                      }
                    } catch (e) {
                      console.warn("Zugriff auf localStorage fehlgeschlagen:", e);
                    }
                    
                    // --- ÄNDERUNG HIER ---
                      // 1. Firebase wird nicht mehr initialisiert
                    // initializeFirebase(); 
                    
                    // 2. CSV-Laden wird ersetzt durch das Einbetten der PHP-Variable
                    try {
                      // <?php echo $futterWerteJson; ?> wird vom Server durch das JSON-Objekt ersetzt
                      const futterWerteFromServer = <?php echo $futterWerteJson; ?>;
                      
                      if (futterWerteFromServer && futterWerteFromServer.erster) {
                        futterWerte = futterWerteFromServer;
                        console.log("Futterwerte erfolgreich vom Server geladen.");
                        showLoading(false);
                      } else {
                        throw new Error("Futterwert-Daten konnten nicht vom Server geladen werden.");
                      }
                    } catch (error) {
                      console.error("Fehler beim Verarbeiten der Server-Daten:", error);
                      document.getElementById('loading-spinner').innerHTML = "Fehler: Futterwerte konnten nicht vom Server verarbeitet werden.";
                      document.getElementById('loading-spinner').classList.add('text-red-500', 'p-4');
                      return; // Stoppt die Ausführung
                    }
                    
                    // 3. Event Listeners (bleibt gleich)
                    setupEventListeners();
                  });
                
                // --- ENTFERNT ---
                  // Die Funktionen initializeFirebase(), loadAndParseCSV() und parseCSV()
                // werden im JavaScript nicht mehr benötigt, da PHP dies übernimmt.
                
                
                // --- UI-EVENT LISTENERS ---
                  // Die Funktion setupEventListeners() bleibt exakt gleich
                // (Da die UI-Logik sich nicht ändert)
                function setupEventListeners() {
                  // Schritt 1: Aufwuchsart
                  document.querySelectorAll('input[name="aufwuchs"]').forEach(radio => {
                    radio.addEventListener('change', () => {
                      document.getElementById('step2-bestand').classList.remove('opacity-20', 'pointer-events-none');
                      const lageWrapper = document.getElementById('step1-5-lage');
                      if (radio.value === 'folge') {
                        lageWrapper.classList.remove('hidden');
                      } else {
                        lageWrapper.classList.add('hidden');
                      }
                      updateStadiumModalOptions(radio.value);
                    });
                  });
                  
                  // Schritt 1.5: Lage
                  document.querySelectorAll('input[name="lage"]').forEach(radio => {
                    radio.addEventListener('change', () => {
                      const aufwuchs = document.querySelector('input[name="aufwuchs"]:checked')?.value;
                      if (aufwuchs) {
                        updateStadiumModalOptions(aufwuchs);
                      }
                    });
                  });
                  
                  // Schritt 2: Bestandestyp
                  document.getElementById('bestandestyp').addEventListener('change', (e) => {
                    if (e.target.value) {
                      document.getElementById('step3-stadium').classList.remove('opacity-20', 'pointer-events-none');
                    } else {
                      document.getElementById('step3-stadium').classList.add('opacity-20', 'pointer-events-none');
                    }
                    checkAllInputs(); 
                  });
                  
                  // Schritt 2: Hilfe-Button Bestand
                  document.getElementById('bestandHelpToggle').addEventListener('click', (e) => {
                    e.preventDefault();
                    document.getElementById('bestandHelpContent').classList.toggle('hidden');
                  });
                  
                  // Schritt 3: Modal öffnen/schließen
                  document.getElementById('openStadiumModal').addEventListener('click', () => {
                    document.getElementById('stadiumModal').classList.remove('hidden');
                  });
                  document.getElementById('closeStadiumModal').addEventListener('click', () => {
                    document.getElementById('stadiumModal').classList.add('hidden');
                  });
                  
                  // Schritt 3: Stadium-Auswahl im Modal
                  document.getElementById('stadium-options').addEventListener('click', (e) => {
                    if (e.target.tagName === 'BUTTON') {
                      const stadium = e.target.dataset.stadium;
                      aktuellesStadiumLabel = e.target.textContent; 
                      document.getElementById('openStadiumModal').textContent = aktuellesStadiumLabel;
                      document.getElementById('openStadiumModal').dataset.selectedStadium = stadium;
                      document.getElementById('stadiumModal').classList.add('hidden');
                      checkAllInputs(); 
                    }
                  });
                  
                  // Schritt 3: Hilfe-Button im Stadium-Modal
                  document.getElementById('toggleStadiumHelp').addEventListener('click', (e) => {
                    const content = document.getElementById('stadiumHelpContent');
                    const isHidden = content.classList.contains('hidden');
                    content.classList.toggle('hidden');
                    e.target.textContent = isHidden ? 'Hilfe ausblenden' : 'Hilfe zur Stadium-Bestimmung anzeigen';
                  });
                  
                  // Schritt 4: Berechnungsbuttons
                  document.querySelectorAll('input[name="konservierung"]').forEach(radio => {
                    radio.addEventListener('click', calculateFutterQualitaet);
                  });
                  
                  // Akkordeons (Parameter & Datenquelle)
                  document.getElementById('accordion-toggle').addEventListener('click', () => {
                    document.getElementById('accordion-content').classList.toggle('hidden');
                    document.getElementById('accordion-icon').classList.toggle('rotate-180');
                  });
                  document.getElementById('data-accordion-toggle').addEventListener('click', () => {
                    document.getElementById('data-accordion-content').classList.toggle('hidden');
                    document.getElementById('data-accordion-icon').classList.toggle('rotate-180');
                  });
                  
                  // Parameter-Änderungen
                  const paramInputs = ['kuhgewicht', 'tsvOverride'];
                  paramInputs.forEach(id => {
                    document.getElementById(id).addEventListener('input', () => {
                      if (!document.getElementById('result-wrapper').classList.contains('hidden')) {
                        applyCorrections();
                      }
                    });
                  });
                  
                  // localStorage Speicherung
                  document.getElementById('personName').addEventListener('input', (e) => {
                    try {
                      localStorage.setItem('agffBeurteilerName', e.target.value);
                    } catch (e) {
                      console.warn("Speichern im localStorage fehlgeschlagen:", e);
                    }
                  });
                  document.getElementById('probeNr').addEventListener('input', (e) => {
                    try {
                      localStorage.setItem('agffProbenNr', e.target.value);
                    } catch (e) {
                      console.warn("Speichern im localStorage fehlgeschlagen:", e);
                    }
                  });
                  
                  
                  // PDF- & Cloud-Speicher-Buttons
                  document.getElementById('savePdfButton').addEventListener('click', handleSavePdf);
                  
                  // --- ÄNDERUNG HIER ---
                    // Der 'saveCloudButton' ruft jetzt die NEUE handleSaveCloud-Funktion auf.
                  document.getElementById('saveCloudButton').addEventListener('click', handleSaveCloud);
                }
                
                // --- UI-HILFSFUNKTIONEN ---
                  // Die Funktionen showLoading(), updateStadiumModalOptions(), checkAllInputs()
                // bleiben exakt gleich.
                function showLoading(isLoading) {
                  const spinner = document.getElementById('loading-spinner');
                  const mainContent = document.getElementById('main-content');
                  if (isLoading) {
                    spinner.classList.remove('hidden');
                    mainContent.classList.add('hidden');
                  } else {
                    spinner.classList.add('hidden');
                    mainContent.classList.remove('hidden');
                  }
                }
                
                function updateStadiumModalOptions(aufwuchsart) {
                  const container = document.getElementById('stadium-options');
                  container.innerHTML = ''; // Inhalt leeren
                  document.getElementById('openStadiumModal').textContent = 'Stadium jetzt bestimmen...';
                  document.getElementById('openStadiumModal').dataset.selectedStadium = '';
                  checkAllInputs();
                  
                  const helpWrapper = document.getElementById('stadiumHelpWrapper');
                  const helpContent = document.getElementById('stadiumHelpContent');
                  const subtitle = document.getElementById('stadiumModalSubtitle');
                  subtitle.textContent = ""; 
                  
                  const hinweisContainer = document.getElementById('folgeHinweisContainer');
                  hinweisContainer.innerHTML = ''; 
                  
                  let options = [];
                  if (aufwuchsart === 'erster') {
                    helpWrapper.classList.remove('hidden'); 
                    options = [
                      { val: '1', label: 'Stadium 1 (Bestockung / Beginn Schossen)' },
                      { val: '2', label: 'Stadium 2 (Schossen / Weidestadium)' },
                      { val: '3', label: 'Stadium 3 (Beginn Rispenschieben, 10%)' },
                      { val: '4', label: 'Stadium 4 (Volles Rispenschieben, 50%)' },
                      { val: '5', label: 'Stadium 5 (Ende Rispenschieben, 90%)' },
                      { val: '6', label: 'Stadium 6 (Blüte)' },
                      { val: '7', label: 'Stadium 7 (Samenreife)' }
                    ];
                    document.getElementById('stadiumModalTitle').textContent = "Stadium 1. Aufwuchs (Leitpflanze)";
                  } else { // 'folge'
                    helpWrapper.classList.add('hidden'); 
                    helpContent.classList.add('hidden');
                    document.getElementById('toggleStadiumHelp').textContent = 'Hilfe zur Stadium-Bestimmung anzeigen';
                    
                    hinweisContainer.innerHTML = `
                    <div class="p-3 bg-yellow-50 text-yellow-800 text-sm rounded-lg border border-yellow-200">
                      <strong>Hinweis:</strong> Bei Wiesen mit einem hohen Anteil an italienischem Raigras oder Bastard-Raigras wird das Stadium anhand des Stadiums des Raigrases wie im ersten Aufwuchs bestimmt.
                    </div>
                      `;
                    
                    const lage = document.querySelector('input[name="lage"]:checked')?.value || 'tal'; 
                    document.getElementById('stadiumModalTitle').textContent = "Stadium Folgeaufwuchs (Alter)";
                    
                    if (lage === 'tal') {
                      subtitle.textContent = "Talgebiet (bis 600m) Sommeraufwüchse (Juli bis August).";
                      options = [
                        { val: '1', label: '3 Wochen (1 sehr früh)' },
                        { val: '2', label: '4 Wochen (2 früh)' },
                        { val: '3', label: '5-6 Wochen (3 mittelfrüh)' },
                        { val: '4', label: '7-8 Wochen (4 mittel)' },
                        { val: '5', label: '9-10 Wochen (5 mittelspät)' },
                        { val: '6', label: '11+ Wochen (6 spät)' }
                      ];
                    } else { // 'berg'
                      subtitle.textContent = "Berggebiet (über 600m) oder späte Tal-Aufwüchse (ab Sept.).";
                      options = [
                        { val: '1', label: '3-4 Wochen (1 sehr früh)' },
                        { val: '2', label: '5-7 Wochen (2 früh)' },
                        { val: '3', label: '8-9 Wochen (3 mittelfrüh)' },
                        { val: '4', label: '10+ Wochen (4 mittel)' }
                      ];
                    }
                  }
                  
                  options.forEach(opt => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.stadium = opt.val;
                    button.textContent = opt.label;
                    button.className = 'w-full text-left p-3 bg-gray-50 hover:bg-green-100 rounded-lg transition-colors';
                    container.appendChild(button);
                  });
                }
                
                function checkAllInputs() {
                  const aufwuchs = document.querySelector('input[name="aufwuchs"]:checked');
                  const bestand = document.getElementById('bestandestyp').value;
                  const stadium = document.getElementById('openStadiumModal').dataset.selectedStadium;
                  
                  const calculationButtons = document.getElementById('step4-calculate-buttons');
                  if (aufwuchs && bestand && stadium) {
                    calculationButtons.classList.remove('opacity-20', 'pointer-events-none');
                  } else {
                    calculationButtons.classList.add('opacity-20', 'pointer-events-none');
                  }
                }
                
                // --- KERNBERECHNUNGEN ---
                  // Alle Berechnungsfunktionen (calculateFutterQualitaet, applyCorrections, calculateMpp)
                // bleiben exakt gleich, da sie nur auf dem 'futterWerte'-Objekt operieren.
                function calculateFutterQualitaet() {
                  const aufwuchs = document.querySelector('input[name="aufwuchs"]:checked')?.value;
                  const bestand = document.getElementById('bestandestyp').value;
                  const stadium = document.getElementById('openStadiumModal').dataset.selectedStadium;
                  const konservierung = document.querySelector('input[name="konservierung"]:checked')?.value;
                  
                  if (!aufwuchs || !bestand || !stadium || !konservierung) {
                    console.error("Berechnung fehlgeschlagen: Nicht alle Eingaben vorhanden.");
                    return;
                  }
                  
                  const basisWerteCheck = futterWerte[aufwuchs]?.[konservierung]?.[bestand];
                  if (!basisWerteCheck || !basisWerteCheck[stadium]) {
                    console.error("Keine Daten für diese Kombination gefunden:", aufwuchs, konservierung, bestand, stadium);
                    document.getElementById('result-wrapper').classList.remove('hidden');
                    document.getElementById('result-content').innerHTML = '<p class="text-red-500">Für diese Auswahl (z.B. Stadium 5 bei Folgeaufwuchs im Berggebiet) sind keine Referenzdaten im Merkblatt vorhanden. Bitte überprüfen Sie Ihre Auswahl.</p>';
                    document.getElementById('step5-corrections').classList.add('hidden');
                    document.getElementById('report-buttons').classList.add('hidden');
                    return;
                  }
                  
                  const basisWerte = futterWerte[aufwuchs][konservierung][bestand][stadium];
                  
                  // Basiswerte im UI-State speichern
                  const resultWrapper = document.getElementById('result-wrapper');
                  resultWrapper.dataset.baseNel = basisWerte.nel;
                  resultWrapper.dataset.baseNev = basisWerte.nev;
                  resultWrapper.dataset.baseApde = basisWerte.apde;
                  resultWrapper.dataset.baseApdn = basisWerte.apdn;
                  // Alle zusätzlichen Werte (falls vorhanden)
                  resultWrapper.dataset.baseRp = basisWerte.rp || 0;
                  resultWrapper.dataset.baseRf = basisWerte.rf || 0;
                  resultWrapper.dataset.baseNdf = basisWerte.ndf || 0;
                  resultWrapper.dataset.baseAdf = basisWerte.adf || 0;
                  resultWrapper.dataset.baseZucker = basisWerte.zucker || 0;
                  resultWrapper.dataset.baseVos = basisWerte.vos || 0;
                  resultWrapper.dataset.baseRa = basisWerte.ra || 0;
                  
                  resultWrapper.classList.remove('hidden');
                  document.getElementById('report-buttons').classList.remove('hidden');
                  
                  generateCorrectionInputs(konservierung);
                  applyCorrections();
                }
                
                function applyCorrections() {
                  const resultWrapper = document.getElementById('result-wrapper');
                  const basisWerte = {
                    nel: parseFloat(resultWrapper.dataset.baseNel),
                    nev: parseFloat(resultWrapper.dataset.baseNev),
                    apde: parseFloat(resultWrapper.dataset.baseApde),
                    apdn: parseFloat(resultWrapper.dataset.baseApdn),
                    rp: parseFloat(resultWrapper.dataset.baseRp),
                    rf: parseFloat(resultWrapper.dataset.baseRf),
                    ndf: parseFloat(resultWrapper.dataset.baseNdf),
                    adf: parseFloat(resultWrapper.dataset.baseAdf),
                    zucker: parseFloat(resultWrapper.dataset.baseZucker),
                    vos: parseFloat(resultWrapper.dataset.baseVos),
                    ra: parseFloat(resultWrapper.dataset.baseRa)
                  };
                  
                  if (isNaN(basisWerte.nel) || basisWerte.nel === 0) return; 
                  
                  let korrFaktoren = { nel: 1, apde: 1, apdn: 1 };
                  let korrLabels = [];
                  
                  document.querySelectorAll('#correction-inputs input:checked').forEach(checkbox => {
                    korrFaktoren.nel *= (1 + parseFloat(checkbox.dataset.nel));
                    korrFaktoren.apde *= (1 + parseFloat(checkbox.dataset.apde));
                    korrFaktoren.apdn *= (1 + parseFloat(checkbox.dataset.apdn));
                    korrLabels.push(checkbox.dataset.label);
                  });
                  
                  const effektiveWerte = {
                    nel: basisWerte.nel * korrFaktoren.nel,
                    nev: basisWerte.nev * korrFaktoren.nel, 
                    apde: basisWerte.apde * korrFaktoren.apde,
                    apdn: basisWerte.apdn * korrFaktoren.apdn
                  };
                  
                  const nelAbweichung = (effektiveWerte.nel - 5.6) / 0.1; 
                  const automatischTSV = 16.0 + (nelAbweichung * 0.3);
                  
                  const tsvOverride = parseFloat(document.getElementById('tsvOverride').value);
                  const effektiverTSV = isNaN(tsvOverride) || tsvOverride <= 0 ? automatischTSV : tsvOverride;
                  
                  const { mpp, limitierenderFaktor } = calculateMpp(effektiveWerte, effektiverTSV);
                  displayResults(basisWerte, effektiveWerte, effektiverTSV, automatischTSV, mpp, limitierenderFaktor, korrLabels);
                }
                
                function calculateMpp(effektiveWerte, tsv) {
                  const kuhGewicht = parseFloat(document.getElementById('kuhgewicht').value) || 630;
                  const EB_NEL = (0.293 * Math.pow(kuhGewicht, 0.75)) * 1.1; 
                  const EB_PROTEIN = (3.25 * Math.pow(kuhGewicht, 0.75)) * 1.1; 
                  const aufnahmeNEL = effektiveWerte.nel * tsv;
                  const aufnahmeAPDE = effektiveWerte.apde * tsv;
                  const aufnahmeAPDN = effektiveWerte.apdn * tsv;
                  const mppNEL = (aufnahmeNEL - EB_NEL) / PB_NEL; 
                  const mppAPDE = (aufnahmeAPDE - EB_PROTEIN) / PB_PROTEIN; 
                  const mppAPDN = (aufnahmeAPDN - EB_PROTEIN) / PB_PROTEIN; 
                  const mpp = Math.max(0, Math.min(mppNEL, mppAPDE, mppAPDN));
                  let limitierenderFaktor = "NEL";
                  if (mppAPDE < mppNEL && mppAPDE < mppAPDN) limitierenderFaktor = "APDE";
                  if (mppAPDN < mppNEL && mppAPDN < mppAPDE) limitierenderFaktor = "APDN";
                  return { mpp, limitierenderFaktor };
                }
                
                // --- DATENANZEIGE & EXPORT ---
                  // displayResults() und generateCorrectionInputs() bleiben exakt gleich.
                function displayResults(basis, korrigiert, tsv, autoTSV, mpp, limit, korrLabels) {
                  const content = document.getElementById('result-content');
                  
                  const createRow = (label, basisVal, korrVal, einheit, bold = false) => {
                    const korrektur = korrVal - basisVal;
                    const korrText = korrektur === 0 ? '-' : (korrektur > 0 ? `+${korrektur.toFixed(2)}` : korrektur.toFixed(2));
                    const basisText = isNaN(basisVal) ? '-' : basisVal.toFixed(2);
                    const effText = isNaN(korrVal) ? '-' : korrVal.toFixed(2);
                    const effId = `eff-${label.toLowerCase()}`;
                    
                    return `
                    <tr class="${bold ? 'font-bold' : ''}">
                      <td class="py-2 pr-2">${label}</td>
                        <td class="py-2 px-2 text-right">${basisText}</td>
                          <td class="py-2 px-2 text-right ${korrektur !== 0 ? (korrektur > 0 ? 'text-green-600' : 'text-red-600') : ''}">${korrText}</td>
                            <td class="py-2 pl-2 text-right"><span id="${effId}">${effText}</span></td>
                              <td class="py-2 pl-2 text-gray-500">${einheit}</td>
                                </tr>`;
                  };
                  
                  const tsvBasisText = autoTSV.toFixed(2);
                  const tsvKorrektur = tsv - autoTSV;
                  const tsvKorrText = tsvKorrektur.toFixed(0) === '0' ? '-' : (tsvKorrektur > 0 ? `+${tsvKorrektur.toFixed(2)}` : tsvKorrektur.toFixed(2));
                  const tsvEffText = tsv.toFixed(2);
                  const tsvRow = `
                  <tr>
                    <td class="py-2 pr-2">Futterverzehr (TSV)</td>
                      <td class="py-2 px-2 text-right">${tsvBasisText}</td>
                        <td class="py-2 px-2 text-right ${tsvKorrektur.toFixed(0) !== '0' ? 'text-blue-600' : ''}">${tsvKorrText}</td>
                          <td class="py-2 pl-2 text-right">${tsvEffText}</td>
                            <td class="py-2 pl-2 text-gray-500">kg TS</td>
                              </tr>`;
                            
                            content.innerHTML = `
                            <h3 class="text-xl font-semibold text-gray-800 mb-3">Berechnete Futterqualität</h3>
                              <div class="overflow-x-auto">
                                <table class="w-full min-w-[340px] text-sm">
                                  <thead>
                                  <tr class="border-b">
                                    <th class="py-2 pr-2 text-left font-medium">Parameter</th>
                                      <th class="py-2 px-2 text-right font-medium">Basiswert</th>
                                        <th class="py-2 px-2 text-right font-medium">Korr.</th>
                                          <th class="py-2 pl-2 text-right font-medium">Effektiv</th>
                                            <th class="py-2 pl-2 text-left font-medium">Einheit</th>
                                              </tr>
                                              </thead>
                                              <tbody>
                                              ${createRow('NEL', basis.nel, korrigiert.nel, 'MJ/kg', limit === 'NEL')}
                                            ${createRow('APDE', basis.apde, korrigiert.apde, 'g/kg', limit === 'APDE')}
                                            ${createRow('APDN', basis.apdn, korrigiert.apdn, 'g/kg', limit === 'APDN')}
                                            ${createRow('NEV', basis.nev, korrigiert.nev, 'MJ/kg')}
                                            ${tsvRow}
                                            </tbody>
                                              </table>
                                              </div>
                                              
                                              <div class="mt-4 p-4 bg-green-50 rounded-lg">
                                                <p class="text-sm text-green-700">Geschätztes Milchproduktionspotenzial (MPP):</p>
                                                  <p class="text-3xl font-bold text-green-800">${mpp.toFixed(1)} kg ECM/Tag</p>
                                                    <p class="text-sm text-gray-600 mt-1">Limitiert durch: <span class="font-semibold">${limit}</span></p>
                                                      </div>
                                                      
                                                      <h4 class="text-lg font-semibold text-gray-800 mt-6 mb-3">Weitere Nährwerte (Basis)</h4>
                                                        <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-2 text-sm">
                                                          <div class="p-2 bg-gray-50 rounded"><span class="font-medium">Rohprotein:</span> ${basis.rp.toFixed(0)} g/kg</div>
                                                            <div class="p-2 bg-gray-50 rounded"><span class="font-medium">Rohfaser:</span> ${basis.rf.toFixed(0)} g/kg</div>
                                                              <div class="p-2 bg-gray-50 rounded"><span class="font-medium">NDF:</span> ${basis.ndf.toFixed(0)} g/kg</div>
                                                                <div class="p-2 bg-gray-50 rounded"><span class="font-medium">ADF:</span> ${basis.adf.toFixed(0)} g/kg</div>
                                                                  <div class="p-2 bg-gray-50 rounded"><span class="font-medium">Zucker:</span> ${basis.zucker.toFixed(0)} g/kg</div>
                                                                    <div class="p-2 bg-gray-50 rounded"><span class="font-medium">vOS:</span> ${basis.vos.toFixed(0)} %</div>
                                                                      <div class="p-2 bg-gray-50 rounded"><span class="font-medium">Rohasche:</span> ${basis.ra.toFixed(0)} g/kg</div>
                                                                        </div>
                                                                        
                                                                        ${korrLabels.length > 0 ? `
                                                                          <div class="mt-6">
                                                                            <h4 class="text-md font-semibold text-gray-700">Angewendete Korrekturen:</h4>
                                                                              <ul class="list-disc list-inside text-sm text-red-600 pl-2">
                                                                                ${korrLabels.map(label => `<li>${label}</li>`).join('')}
                                                                              </ul>
                                                                                </div>` : ''}
                                                                      `;
                }
                
                function generateCorrectionInputs(konservierung) {
                  const container = document.getElementById('correction-inputs');
                  container.innerHTML = '';
                  const stepWrapper = document.getElementById('step5-corrections');
                  
                  const correctionFactors = {
                    silage: [ 
                      { id: "ts_low", label: "Trockensubstanz (TS) < 20%", nel: -0.01, apde: -0.06, apdn: 0 },
                      { id: "ts_high", label: "Trockensubstanz (TS) > 50%", nel: -0.01, apde: 0.06, apdn: 0.05 },
                      { id: "gaer_bad", label: "Gärqualität fehlerhaft", nel: -0.02, apde: -0.06, apdn: -0.05 },
                      { id: "gaer_vbad", label: "Gärqualität schlecht", nel: -0.05, apde: -0.15, apdn: -0.12 },
                      { id: "nachgaer", label: "Nachgärung (leicht warm)", nel: -0.04, apde: -0.15, apdn: -0.03 }
                    ],
                    hay: [ 
                      { id: "boden", label: "Bodentrocknung", nel: -0.04, apde: -0.03, apdn: 0 },
                      { id: "regen_1", label: "Witterung: 1 Tag Regen", nel: -0.05, apde: -0.08, apdn: -0.02 },
                      { id: "regen_2", label: "Witterung: 2+ Tage Regen", nel: -0.08, apde: -0.15, apdn: -0.03 },
                      { id: "ueber_leicht", label: "Überhitzung: Futter leicht braun", nel: 0, apde: 0.03, apdn: 0 },
                      { id: "ueber_stark", label: "Überhitzung: Futter braun, brandig", nel: -0.05, apde: -0.01, apdn: -0.02 }
                    ]
                  };
                  
                  correctionFactors.silage[1].apdn = 0.05; 
                  correctionFactors.silage[2].apdn = -0.05; 
                  correctionFactors.silage[3].apdn = -0.12; 
                  
                  const factors = correctionFactors[konservierung];
                  if (!factors) {
                    stepWrapper.classList.add('hidden');
                    return; 
                  }
                  
                  stepWrapper.classList.remove('hidden');
                  
                  factors.forEach(factor => {
                    const label = document.createElement('label');
                    label.className = 'flex items-center p-3 bg-white rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 cursor-pointer';
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.id = factor.id;
                    input.className = 'h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500';
                    input.dataset.nel = factor.nel;
                    input.dataset.apde = factor.apde;
                    input.dataset.apdn = factor.apdn;
                    input.dataset.label = factor.label;
                    input.addEventListener('change', applyCorrections);
                    const labelText = document.createElement('span');
                    labelText.className = 'ml-3 text-sm text-gray-700';
                    labelText.textContent = `${factor.label} (NEL: ${factor.nel*100}%, APDE: ${factor.apde*100}%, APDN: ${factor.apdn*100}%)`;
                    label.appendChild(input);
                    label.appendChild(labelText);
                    container.appendChild(label);
                  });
                }
                
                // handleSavePdf() bleibt exakt gleich, da es nur Client-Daten liest
                async function handleSavePdf() {
                  const { jsPDF } = window.jspdf;
                  const doc = new jsPDF();
                  const formData = getFormData();
                  const results = getResultData();
                  
                  if (!results || isNaN(results.mpp)) {
                    // (alert() ist in Canvas nicht ideal, aber wir übernehmen es mal)
                    console.warn("PDF-Speicherung: Bitte zuerst Berechnung durchführen.");
                    return;
                  }
                  doc.setFont('helvetica', 'bold');
                  doc.setFontSize(18);
                  doc.setTextColor(40, 40, 40);
                  doc.text("Futterbewertungs-Bericht", 105, 20, { align: 'center' });
                  doc.setDrawColor(200, 200, 200);
                  doc.line(20, 25, 190, 25);
                  doc.setFont('helvetica', 'normal');
                  doc.setFontSize(11);
                  doc.setTextColor(100, 100, 100);
                  doc.text(`Bericht erstellt am: ${new Date().toLocaleString('de-CH')}`, 105, 32, { align: 'center' });
                  let y = 45;
                  doc.setFontSize(14);
                  doc.setFont('helvetica', 'bold');
                  doc.setTextColor(67, 131, 81); // App-Grün
                  doc.text("1. Probe-Informationen", 20, y);
                  y += 8;
                  doc.setFont('helvetica', 'normal');
                  doc.setFontSize(12);
                  doc.setTextColor(0, 0, 0);
                  doc.text(`Beurteiler: ${formData.personName}`, 25, y);
                  y += 7;
                  doc.text(`Proben-Nr: ${formData.probeNr}`, 25, y);
                  y += 12;
                  doc.setFontSize(14);
                  doc.setFont('helvetica', 'bold');
                  doc.setTextColor(67, 131, 81);
                  doc.text("2. Futter-Spezifikation", 20, y);
                  y += 8;
                  doc.setFont('helvetica', 'normal');
                  doc.setFontSize(12);
                  doc.setTextColor(0, 0, 0);
                  doc.text(`Aufwuchs: ${formData.aufwuchsLabel}`, 25, y);
                  y += 7;
                  if (formData.aufwuchs === 'folge') {
                    doc.text(`Lage: ${formData.lageLabel}`, 25, y);
                    y += 7;
                  }
                  doc.text(`Bestandestyp: ${formData.bestandLabel} (${formData.bestand})`, 25, y);
                  y += 7;
                  doc.text(`Stadium: ${formData.stadiumLabel}`, 25, y);
                  y += 7;
                  doc.text(`Konservierung: ${formData.konservierungLabel}`, 25, y);
                  y += 12;
                  doc.setFontSize(14);
                  doc.setFont('helvetica', 'bold');
                  doc.setTextColor(67, 131, 81);
                  doc.text("3. Berechnungsparameter", 20, y);
                  y += 8;
                  doc.setFont('helvetica', 'normal');
                  doc.setFontSize(12);
                  doc.setTextColor(0, 0, 0);
                  doc.text(`Kuhgewicht (LG): ${formData.kuhGewicht} kg`, 25, y);
                  y += 7;
                  doc.text(`Futterverzehr (TSV): ${results.effektiverTSV.toFixed(2)} kg TS`, 25, y);
                  if (results.tsvUeberschrieben) {
                    doc.setFontSize(10);
                    doc.setTextColor(100, 100, 100);
                    y += 5;
                    doc.text(`(Manuell überschrieben. Automatisch wäre: ${results.automatischTSV.toFixed(2)} kg TS)`, 25, y);
                    y += 3; 
                  }
                  y += 12;
                  doc.setFontSize(14);
                  doc.setFont('helvetica', 'bold');
                  doc.setTextColor(67, 131, 81);
                  doc.text("4. Ergebnisse Nährwerte", 20, y);
                  y += 8;
                  doc.setFont('helvetica', 'bold');
                  doc.text('Parameter', 25, y);
                  doc.text('Basis', 70, y, { align: 'right' });
                  doc.text('Korr.', 95, y, { align: 'right' });
                  doc.text('Effektiv', 120, y, { align: 'right' });
                  doc.text('Einheit', 125, y);
                  y += 7;
                  doc.setFont('helvetica', 'normal');
                  
                  const printRow = (label, b, k, e, u, highlight = false) => {
                    const b_str = isNaN(b) ? 'N/A' : b.toFixed(2);
                    const k_str = isNaN(k) ? 'N/A' : (k > 0 ? `+${k.toFixed(2)}` : k.toFixed(2));
                    const e_str = isNaN(e) ? 'N/A' : e.toFixed(2);
                    if (highlight) doc.setFont('helvetica', 'bold');
                    doc.text(label, 25, y);
                    doc.text(b_str, 70, y, { align: 'right' });
                    doc.text(k_str, 95, y, { align: 'right' });
                    doc.text(e_str, 120, y, { align: 'right' });
                    doc.text(u, 125, y);
                    if (highlight) doc.setFont('helvetica', 'normal');
                    y += 7;
                  };
                  
                  printRow('NEL', results.basis.nel, results.korr.nel, results.eff.nel, 'MJ/kg', results.limit === 'NEL');
                  printRow('APDE', results.basis.apde, results.korr.apde, results.eff.apde, 'g/kg', results.limit === 'APDE');
                  printRow('APDN', results.basis.apdn, results.korr.apdn, results.eff.apdn, 'g/kg', results.limit === 'APDN');
                  printRow('NEV', results.basis.nev, results.korr.nev, results.eff.nev, 'MJ/kg');
                  y += 7;
                  doc.setFontSize(14);
                  doc.setFont('helvetica', 'bold');
                  doc.setTextColor(0, 0, 0);
                  doc.text("Milchproduktionspotenzial (MPP):", 20, y);
                  doc.setFontSize(18);
                  doc.text(`${results.mpp.toFixed(1)} kg ECM/Tag`, 185, y, { align: 'right' });
                  doc.setFontSize(11);
                  doc.setTextColor(100, 100, 100);
                  y += 6;
                  doc.text(`(Limitiert durch ${results.limit})`, 185, y, { align: 'right' });
                  doc.setTextColor(0, 0, 0);
                  if (formData.korrekturen.length > 0) {
                    y += 12;
                    doc.setFontSize(14);
                    doc.setFont('helvetica', 'bold');
                    doc.setTextColor(67, 131, 81);
                    doc.text("5. Angewendete Korrekturen", 20, y);
                    y += 8;
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(12);
                    doc.setTextColor(192, 0, 0); // Rot
                    formData.korrekturen.forEach(label => {
                      doc.text(`- ${label}`, 25, y);
                      y += 7;
                    });
                  }
                  doc.save(`Futterbericht_${formData.probeNr || 'Prototyp'}.pdf`);
                }
                
                /**
                  * --- GRUNDLEGENDE ÄNDERUNG HIER ---
                  * Speichert die Daten, indem es sie an das PHP-Backend (diese Datei selbst) sendet.
                */
                  async function handleSaveCloud() {
                    // Schritt 1 & 2 (Daten sammeln) bleiben gleich
                    const formData = getFormData();
                    const results = getResultData();
                    
                    if (isNaN(results.mpp)) {
                      // (alert() ist nicht ideal)
                      console.warn("Speichern: Bitte zuerst Berechnung durchführen.");
                      return;
                    }
                    if (!formData.personName || !formData.probeNr) {
                      console.warn("Speichern: Bitte Name und Proben-Nr. angeben.");
                      document.getElementById('personName').focus();
                      return;
                    }
                    
                    const saveButton = document.getElementById('saveCloudButton');
                    saveButton.disabled = true;
                    saveButton.textContent = "Speichern...";
                    
                    // Das zu speichernde Objekt (genau wie vorher)
                    const dataToSave = {
                      beurteiler: formData.personName,
                      probeNr: formData.probeNr,
                      aufwuchs: formData.aufwuchs,
                      aufwuchsLabel: formData.aufwuchsLabel,
                      lage: formData.lage, 
                      lageLabel: formData.lageLabel, 
                      bestand: formData.bestand,
                      bestandLabel: formData.bestandLabel,
                      stadium: formData.stadium,
                      stadiumLabel: formData.stadiumLabel,
                      konservierung: formData.konservierung,
                      konservierungLabel: formData.konservierungLabel,
                      kuhGewicht: formData.kuhGewicht,
                      angewendeteKorrekturen: formData.korrekturen,
                      mpp: results.mpp,
                      limitierenderFaktor: results.limit,
                      effektiverTSV: results.effektiverTSV,
                      automatischTSV: results.automatischTSV,
                      tsvUeberschrieben: results.tsvUeberschrieben,
                      effektiveWerte: results.eff,
                      basisWerte: results.basis,
                      korrekturWerte: results.korr,
                      // NEU: Wir senden das Datum als ISO-String. 
                      // Das PHP Admin SDK kann dies in einen Timestamp umwandeln.
                      erstelltAm: new Date().toISOString() 
                      // userId (kann vom Server-Backend hinzugefügt werden, falls nötig)
                    };
                    
                    // Schritt 3: Sende an das PHP-Backend statt an Firebase
                    try {
                      // Wir senden an 'index.php' (diese Datei) mit dem 'action'-Parameter
                      const response = await fetch('index.php?action=save', {
                        method: 'POST',
                        headers: {
                          'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(dataToSave)
                      });
                      
                      const result = await response.json();
                      
                      if (!response.ok) {
                        // Falls der Server einen 500er-Fehler o.ä. sendet
                        throw new Error(result.message || `Server-Fehler (${response.status})`);
                      }
                      
                      // Erfolg!
                        saveButton.textContent = "Gespeichert!";
                      saveButton.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                      saveButton.classList.add('bg-green-600');
                      console.log("Server-Antwort:", result);
                      
                      setTimeout(() => {
                        saveButton.disabled = false;
                        saveButton.textContent = "In Cloud speichern";
                        saveButton.classList.remove('bg-green-600');
                        saveButton.classList.add('bg-blue-600', 'hover:bg-blue-700');
                      }, 2000);
                      
                    } catch (error) {
                      console.error("Fehler beim Speichern auf dem Server:", error);
                      // (alert() ist nicht ideal)
                      alert("Fehler beim Speichern der Daten. Details siehe Konsole.");
                      saveButton.disabled = false;
                      saveButton.textContent = "In Cloud speichern";
                    }
                  }
                
                // getFormData() und getResultData() bleiben exakt gleich
                function getFormData() {
                  const bestandSelect = document.getElementById('bestandestyp');
                  const aufwuchsSelect = document.querySelector('input[name="aufwuchs"]:checked');
                  const konservSelect = document.querySelector('input[name="konservierung"]:checked');
                  const lageSelect = document.querySelector('input[name="lage"]:checked'); 
                  
                  const korrekturen = [];
                  document.querySelectorAll('#correction-inputs input:checked').forEach(cb => {
                    korrekturen.push(cb.dataset.label);
                  });
                  
                  return {
                    personName: document.getElementById('personName').value,
                    probeNr: document.getElementById('probeNr').value,
                    aufwuchs: aufwuchsSelect?.value,
                    aufwuchsLabel: aufwuchsSelect?.value === 'erster' ? '1. Aufwuchs' : 'Folgeaufwuchs',
                    lage: lageSelect?.value, 
                    lageLabel: lageSelect?.labels[0].textContent.trim(), 
                    bestand: bestandSelect.value,
                    bestandLabel: bestandSelect.options[bestandSelect.selectedIndex]?.text,
                    stadium: document.getElementById('openStadiumModal').dataset.selectedStadium,
                    stadiumLabel: aktuellesStadiumLabel,
                    konservierung: konservSelect?.value,
                    konservierungLabel: konservSelect?.dataset.label,
                    kuhGewicht: parseFloat(document.getElementById('kuhgewicht').value) || 630,
                    korrekturen: korrekturen
                  };
                }
                
                function getResultData() {
                  const resultWrapper = document.getElementById('result-wrapper');
                  const basisNEL = parseFloat(resultWrapper.dataset.baseNel);
                  const effNEL = parseFloat(document.getElementById('eff-nel')?.textContent || 0);
                  
                  const automatischTSV = 16.0 + ((effNEL - 5.6) / 0.1 * 0.3);
                  const tsvOverride = parseFloat(document.getElementById('tsvOverride').value);
                  const effektiverTSV = isNaN(tsvOverride) || tsvOverride <= 0 ? automatischTSV : tsvOverride;
                  
                  const effAPDE = parseFloat(document.getElementById('eff-apde')?.textContent || 0);
                  const effAPDN = parseFloat(document.getElementById('eff-apdn')?.textContent || 0);
                  
                  if (isNaN(effNEL) || effNEL === 0) {
                    return { mpp: NaN }; 
                  }
                  
                  const { mpp, limitierenderFaktor } = calculateMpp(
                    { nel: effNEL, apde: effAPDE, apdn: effAPDN },
                    effektiverTSV
                  );
                  
                  return {
                    mpp: mpp,
                    limit: limitierenderFaktor,
                    automatischTSV: automatischTSV,
                    effektiverTSV: effektiverTSV,
                    tsvUeberschrieben: !isNaN(tsvOverride) && tsvOverride > 0,
                    basis: {
                      nel: basisNEL,
                      nev: parseFloat(resultWrapper.dataset.baseNev),
                      apde: parseFloat(resultWrapper.dataset.baseApde),
                      apdn: parseFloat(resultWrapper.dataset.baseApdn)
                    },
                    eff: {
                      nel: effNEL,
                      nev: parseFloat(document.getElementById('eff-nev')?.textContent || 0),
                      apde: effAPDE,
                      apdn: effAPDN
                    },
                    korr: {
                      nel: effNEL - basisNEL,
                      nev: (parseFloat(document.getElementById('eff-nev')?.textContent || 0)) - (parseFloat(resultWrapper.dataset.baseNev)),
                      apde: effAPDE - (parseFloat(resultWrapper.dataset.baseApde)),
                      apdn: effAPDN - (parseFloat(resultWrapper.dataset.baseApdn))
                    }
                  };
                }
                
                </script>
                  
                  <style>
                  body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    -webkit-font-smoothing: antialiased;
                    -moz-osx-font-smoothing: grayscale;
                  }
                input[type='number']::-webkit-inner-spin-button,
                input[type='number']::-webkit-outer-spin-button {
                  -webkit-appearance: none;
                  margin: 0;
                }
                input[type='number'] {
                  -moz-appearance: textfield;
                }
                </style>
                  </head>
                  
                  <body class="bg-gray-100 min-h-screen">
                  
                  <!-- Der Lade-Spinner (bleibt gleich) -->
                  <div id="loading-spinner" class="fixed inset-0 flex items-center justify-center bg-white z-50">
                  <div class="flex flex-col items-center">
                  <svg class="animate-spin h-10 w-10 text-green-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <p id="loading-message" class="mt-4 text-gray-600">Lade Futterwert-Daten...</p>
                  </div>
                  </div>
                  
                  <!-- Das gesamte HTML für die UI (Header, Main, Modal)
                bleibt exakt dasselbe wie in Ihrer index.html.
                Es wird hier aus Gründen der Übersichtlichkeit nicht wiederholt,
                wird aber von PHP nach dem <?php ... ?> Block gesendet. 
                -->
                  <div id="main-content" class="hidden">
                  
                  <header class="bg-white shadow-sm sticky top-0 z-30">
                  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                  <div class="flex justify-between items-center h-16">
                  <!-- Titel-Bereich mit Kuh-Icon und Links -->
                  <div class="flex items-center">
                  <span class="text-3xl flex-shrink-0">🐮</span>
                  <div class="ml-3">
                  <h1 class="text-2xl font-semibold text-gray-800">Futterwert-Schätzung</h1>
                  <div class="flex items-center text-sm text-gray-500">
                  <a href="https://www.eagff.ch/wiesenpflanzen-kennen/graeser/entwicklungsstadien/einleitung-definition" target="_blank" rel="noopener noreferrer" class="hover:text-blue-600 hover:underline">
                  basierend auf AGFF Merkblatt Nr. 3
                </a>
                  <a href="https://www.eagff.ch/files/images/bilder/Raufutter_produzieren/Futterqualitaet/agff-mb3_1707_D_21_bewertung_von_wiesenfutter_ohne_06.05.pdf" target="_blank" rel="noopener noreferrer" class="ml-2 transition-transform duration-150 ease-in-out hover:scale-110" title="AGFF-Merkblatt 3 als PDF öffnen">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  </a>
                  </div>
                  </div>
                  </div>
                  </div>
                  </div>
                  </header>
                  
                  <main class="max-w-4xl mx-auto p-4 sm:p-6 lg:p-8">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                  
                  <!-- Linke Spalte (Eingaben) -->
                  <div class="space-y-6">
                  
                  <div class="p-5 bg-white rounded-2xl shadow-lg space-y-4">
                  <h2 class="text-lg font-semibold text-gray-700 border-b pb-2">Probe-Informationen</h2>
                  <div>
                  <label for="personName" class="block text-sm font-medium text-gray-600 mb-1">Name Beurteiler</label>
                  <input type="text" id="personName" placeholder="Max Mustermann" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                  </div>
                  <div>
                  <label for="probeNr" class="block text-sm font-medium text-gray-600 mb-1">Proben-Nr. / Schlag</label>
                  <input type="text" id="probeNr" placeholder="Wiese 3" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                  </div>
                  </div>
                  
                  <div class="p-5 bg-white rounded-2xl shadow-lg">
                  <label class="text-lg font-semibold text-gray-700">Schritt 1: Aufwuchsart</label>
                  <fieldset class="mt-4">
                  <legend class="sr-only">Aufwuchsart wählen</legend>
                  <div class="relative bg-gray-100 rounded-lg p-1 flex">
                  <input type="radio" name="aufwuchs" id="aufwuchs-erster" value="erster" class="sr-only peer/erster">
                  <label for="aufwuchs-erster" class="flex-1 text-center p-2 rounded-md cursor-pointer text-gray-600 peer-checked/erster:bg-white peer-checked/erster:shadow peer-checked/erster:text-green-700 font-medium transition-all">
                  1. Aufwuchs
                </label>
                  <input type="radio" name="aufwuchs" id="aufwuchs-folge" value="folge" class="sr-only peer/folge">
                  <label for="aufwuchs-folge" class="flex-1 text-center p-2 rounded-md cursor-pointer text-gray-600 peer-checked/folge:bg-white peer-checked/folge:shadow peer-checked/folge:text-green-700 font-medium transition-all">
                  Folgeaufwuchs
                </label>
                  </div>
                  </fieldset>
                  </div>
                  
                  <div id="step1-5-lage" class="p-5 bg-white rounded-2xl shadow-lg hidden">
                  <label class="text-lg font-semibold text-gray-700">Lage des Betriebs (für Folgeaufwuchs)</label>
                  <fieldset class="mt-4">
                  <legend class="sr-only">Lage wählen</legend>
                  <div class="relative bg-gray-100 rounded-lg p-1 flex">
                  <input type="radio" name="lage" id="lage-tal" value="tal" class="sr-only peer/tal" checked>
                  <label for="lage-tal" class="flex-1 text-center p-2 rounded-md cursor-pointer text-gray-600 peer-checked/tal:bg-white peer-checked/tal:shadow peer-checked/tal:text-green-700 font-medium transition-all">
                  Talgebiet (bis 600m) Sommeraufwüchse (Juli bis August)
                </label>
                  <input type="radio" name="lage" id="lage-berg" value="berg" class="sr-only peer/berg">
                  <label for="lage-berg" class="flex-1 text-center p-2 rounded-md cursor-pointer text-gray-600 peer-checked/berg:bg-white peer-checked/berg:shadow peer-checked/berg:text-green-700 font-medium transition-all">
                  Berggebiet (über 600m) oder späte Tal-Aufwüchse (ab Sept.)
                </label>
                  </div>
                  </fieldset>
                  </div>
                  
                  <div id="step2-bestand" class="p-5 bg-white rounded-2xl shadow-lg opacity-20 pointer-events-none transition-opacity">
                  <div class="flex justify-between items-center">
                  <label for="bestandestyp" class="text-lg font-semibold text-gray-700">Schritt 2: Bestandestyp</label>
                  <button id="bestandHelpToggle" class="text-sm text-blue-600 hover:text-blue-800 focus:outline-none">Info</button>
                  </div>
                  <div id="bestandHelpContent" class="hidden mt-3 p-3 bg-gray-50 rounded-lg text-sm text-gray-600 space-y-2">
                  <p class="font-medium">Hilfe zur Einschätzung (gem. Merkblatt S. 1):</p>
                  <ul class="list-disc list-inside">
                  <li><strong>G/GR (Gräserreich):</strong> > 70% Gräser </li>
                  <li><strong>A/AR (Ausgewogen):</strong> 50-70% Gräser</li>
                  <li><strong>L (Leguminosenreich):</strong> > 50% Kleearten</li>
                  <li><strong>KF/KG (Kräuterreich):</strong> > 50% Kräuter</li>
                  </ul>
                  <p class="text-xs">Der Zusatz <strong>'R' (Raigräser)</strong> wird gewählt, wenn > 50% der Gräser Raigräser sind.</p>
                  </div>
                  
                  <select id="bestandestyp" class="mt-4 w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                  <option value="">Bitte wählen...</option>
                  <option value="G">G: Gräserreich (andere Gräser)</option>
                  <option value="GR">GR: Gräserreich (Raigräser)</option>
                  <option value="A">A: Ausgewogen (andere Gräser)</option>
                  <option value="AR">AR: Ausgewogen (Raigräser)</option>
                  <option value="L">L: Leguminosenreich</option>
                  <option value="KF">KF: Kräuterreich (feinblättrig)</option>
                  <option value="KG">KG: Kräuterreich (grobstängelig)</option>
                  </select>
                  </div>
                  
                  <div id="step3-stadium" class="p-5 bg-white rounded-2xl shadow-lg opacity-20 pointer-events-none transition-opacity">
                  <label class="text-lg font-semibold text-gray-700">Schritt 3: Entwicklungsstadium</label>
                  <button id="openStadiumModal" data-selected-stadium="" type="button" class="mt-4 w-full p-2 text-left border border-gray-300 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-green-500">
                  Stadium jetzt bestimmen...
                </button>
                  </div>
                  
                  <div id="step4-calculate-buttons" class="p-5 bg-white rounded-2xl shadow-lg opacity-20 pointer-events-none transition-opacity">
                  <label class="text-lg font-semibold text-gray-700">Schritt 4: Konservierung & Berechnung</label>
                  <fieldset class="mt-4 space-y-3">
                  <legend class="sr-only">Konservierungsart wählen und berechnen</legend>
                  <input type="radio" name="konservierung" id="kons-greenfeed" value="greenfeed" data-label="Grünfutter" class="sr-only peer/green">
                  <label for="kons-greenfeed" class="flex items-center justify-center p-4 rounded-lg cursor-pointer bg-green-600 text-white font-semibold shadow-md hover:bg-green-700 transition-all peer-checked/green:ring-2 peer-checked/green:ring-offset-2 peer-checked/green:ring-green-500">
                  <span class="text-xl mr-2">🍀</span>
                  Grünfutter Qualität berechnen
                </label>
                  
                  <input type="radio" name="konservierung" id="kons-silage" value="silage" data-label="Silage" class="sr-only peer/silage">
                  <label for="kons-silage" class="flex items-center justify-center p-4 rounded-lg cursor-pointer bg-blue-600 text-white font-semibold shadow-md hover:bg-blue-700 transition-all peer-checked/silage:ring-2 peer-checked/silage:ring-offset-2 peer-checked/silage:ring-blue-500">
                  <span class="text-xl mr-2">⚪</span>
                  Silage Qualität berechnen
                </label>
                  
                  <input type="radio" name="konservierung" id="kons-hay" value="hay" data-label="Dürrfutter" class="sr-only peer/hay">
                  <label for="kons-hay" class="flex items-center justify-center p-4 rounded-lg cursor-pointer bg-yellow-600 text-white font-semibold shadow-md hover:bg-yellow-700 transition-all peer-checked/hay:ring-2 peer-checked/hay:ring-offset-2 peer-checked/hay:ring-yellow-500">
                  <span class="text-xl mr-2">🟨</span>
                  Dürrfutter Qualität berechnen
                </label>
                  </fieldset>
                  </div>
                  </div>
                  
                  <!-- Rechte Spalte (Ergebnisse) -->
                  <div class="space-y-6">
                  <div id="result-wrapper" class="p-5 bg-white rounded-2xl shadow-lg hidden"
                data-base-nel="0" data-base-nev="0" data-base-apde="0" data-base-apdn="0"
                data-base-rp="0" data-base-rf="0" data-base-ndf="0" data-base-adf="0"
                data-base-zucker="0" data-base-vos="0" data-base-ra="0">
                  
                  <div id="result-content"></div>
                  
                  </div>
                  
                  <div id="parameters-accordion" class="bg-white rounded-2xl shadow-lg">
                  <button id="accordion-toggle" type="button" class="flex justify-between items-center w-full p-5 font-semibold text-gray-700 text-left">
                  <span>Optionale Berechnungsparameter</span>
                  <svg id="accordion-icon" class="w-6 h-6 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                  </button>
                  <div id="accordion-content" class="hidden p-5 border-t border-gray-200 space-y-4">
                  <div>
                  <label for="kuhgewicht" class="block text-sm font-medium text-gray-600 mb-1">Kuhgewicht (LG) in kg</label>
                  <input type="number" id="kuhgewicht" value="630" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                  <p class="text-xs text-gray-500 mt-1">Standardwert gem. Merkblatt: 630 kg.</p>
                  </div>
                  <div>
                  <label for="tsvOverride" class="block text-sm font-medium text-gray-600 mb-1">Futterverzehr (kg TS) überschreiben</label>
                  <input type="number" step="0.1" id="tsvOverride" placeholder="Automatisch berechnet" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                  <p class="text-xs text-gray-500 mt-1">Leer lassen für automatische Schätzung (Basis: 16kg @ 5.6 NEL, +/- 0.3kg pro 0.1 MJ NEL).</p>
                  </div>
                  </div>
                  </div>
                  
                  <div id="step5-corrections" class="p-5 bg-white rounded-2xl shadow-lg hidden">
                  <label class="text-lg font-semibold text-gray-700">Schritt 5: Korrekturen & Abzüge</label>
                  <div id="correction-inputs" class="mt-4 space-y-3">
                  </div>
                  </div>
                  
                  <div id="report-buttons" class="hidden space-y-3">
                  <button id="savePdfButton" class="flex items-center justify-center w-full p-4 rounded-lg cursor-pointer bg-gray-600 text-white font-semibold shadow-md hover:bg-gray-700 transition-all">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  PDF-Bericht speichern
                </button>
                  <button id="saveCloudButton" class="flex items-center justify-center w-full p-4 rounded-lg cursor-pointer bg-blue-600 text-white font-semibold shadow-md hover:bg-blue-700 transition-all">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                  </svg>
                  <!-- Text wird von JS gesteuert -->
                  In Cloud speichern
                </button>
                  </div>
                  
                  <!-- Akkordeon für Datenquelle (bleibt gleich) -->
                  <div id="data-accordion" class="bg-white rounded-2xl shadow-lg">
                  <button id="data-accordion-toggle" type="button" class="flex justify-between items-center w-full p-5 font-semibold text-gray-700 text-left">
                  <span>Datenquelle (Rohdaten)</span>
                  <svg id="data-accordion-icon" class="w-6 h-6 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                  </button>
                  <div id="data-accordion-content" class="hidden p-5 border-t border-gray-200 space-y-3">
                  <p class="text-sm text-gray-600">
                  Die Berechnungen basieren auf den Daten der folgenden AGROSCOPE-Tabelle.
                </p>
                  <a href="./TABLES_Fourrages_Raufutter_AGROSCOPE2017_mit Farbskalen_v2025-11-05_zbma.xlsx" 
                target="_blank" 
                class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors group">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-700 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  <div>
                  <span class="font-medium text-gray-800 group-hover:text-blue-600">Rohdaten-Tabelle (.xlsx)</span>
                  <p class="text-sm text-gray-500">Klicken zum Herunterladen/Öffnen</p>
                  </div>
                  </a>
                  <p class="text-xs text-gray-500">
                  <strong>Hinweis:</strong> Die Datei muss sich im selben Ordner wie die App befinden, damit dieser Link funktioniert.
                </p>
                  </div>
                  </div>
                  
                  </div>
                  
                  </div>
                  </main>
                  </div>
                  
                  <!-- Das Stadium-Modal (bleibt exakt gleich) -->
                  <div id="stadiumModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 flex items-center justify-center z-40 p-4 hidden">
                  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                  <div class="flex justify-between items-center p-5 border-b">
                  <div>
                  <h3 id="stadiumModalTitle" class="text-lg font-semibold text-gray-800">Stadium bestimmen</h3>
                  <p id="stadiumModalSubtitle" class="text-sm text-gray-500 mt-1"></p>
                  <div id="folgeHinweisContainer" class="mt-3"></div>
                  </div>
                  <button id="closeStadiumModal" class="text-gray-400 hover:text-gray-600">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                  </button>
                  </div>
                  
                  <div id="stadiumHelpWrapper" class="p-5 border-b">
                  <button id="toggleStadiumHelp" class="w-full text-sm text-blue-600 hover:text-blue-800 focus:outline-none">
                  Hilfe zur Stadium-Bestimmung anzeigen
                </button>
                  <div id="stadiumHelpContent" class="hidden mt-4 space-y-3 max-h-[40vh] overflow-y-auto">
                  <p class="text-sm text-gray-600">Bestimmen Sie das Stadium der Leitpflanze (Gräser) gemäss AGFF Merkblatt 3 (S. 1, 3-4).</p>
                  <img src="stadien-uebersicht.webp" alt="Entwicklungsstadien 1-7 von Wiesengräsern" class="w-full rounded-lg border border-gray-200">
                  <ul class="list-disc list-inside text-sm space-y-1">
                  <li><strong>Stadium 1:</strong> Bestockung - Beginn Schossen</li>
                  <li><strong>Stadium 2:</strong> Schossen (Weidestadium, 10 cm-Punkt)</li>
                  <li><strong>Stadium 3:</strong> Beginn Rispenschieben (10% der Rispen sichtbar)</li>
                  <li><strong>Stadium 4:</strong> Volles Rispenschieben (50% der Rispen sichtbar)</li>
                  <li><strong>Stadium 5:</strong> Ende Rispenschieben (90% der Rispen sichtbar)</li>
                  <li><strong>Stadium 6:</strong> Blüte (Staubbeutel auf allen Rispen sichtbar)</li>
                  <li><strong>Stadium 7:</strong> Samenreife</li>
                  </ul>
                  <p class="text-sm text-gray-600 mt-2"><strong>Beispielbilder (Leitpflanzen):</strong></p>
                  <div class="grid grid-cols-2 gap-2">
                  <figure>
                  <img src="knaulgras.webp" alt="Knaulgras Blüte (Std. 6)" class="w-full rounded border border-gray-200">
                  <figcaption class="text-xs text-center text-gray-500 mt-1">Knaulgras </figcaption>
                  </figure>
                  <figure>
                  <img src="rotklee.webp" alt="Rotklee Vollblüte (Std. 5)" class="w-full rounded border border-gray-200">
                  <figcaption class="text-xs text-center text-gray-500 mt-1">Rotklee </figcaption>
                  </figure>
                  <figure>
                  <img src="eng-raigras.webp" alt="Eng. Raigras Rispenschieben (Std. 3)" class="w-full rounded border border-gray-200">
                  <figcaption class="text-xs text-center text-gray-500 mt-1">Eng. Raigras </figcaption>
                  </figure>
                  <figure>
                  <img src="loewenzahn.webp" alt="Löwenzahn Samenstände (Std. 5)" class="w-full rounded border border-gray-200">
                  <figcaption class="text-xs text-center text-gray-500 mt-1">Löwenzahn</figcaption>
                  </figure>
                  </div>
                  </div>
                  </div>
                  
                  <div id="stadium-options" class="p-5 space-y-2 max-h-[40vh] overflow-y-auto">
                  <!-- Inhalt wird von JS (updateStadiumModalOptions) generiert -->
                  </div>
                  </div>
                  </div>
                  
                  </body>
                  </html>