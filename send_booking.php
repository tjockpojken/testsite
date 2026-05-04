<?php
// ============================================================
//  Lindängen BRF — Bokningsnotifiering (Gästlägenhet)
//  Ladda upp denna fil till roten av din one.com webbserver.
// ============================================================

// ── INSTÄLLNINGAR — fyll i dina uppgifter här ──────────────
define('SMTP_HOST',     'send.one.com');
define('SMTP_PORT',     465);
define('SMTP_USER',     'din-epost@dindoman.se');   // din one.com e-postadress
define('SMTP_PASS',     'ditt-losenord');            // lösenord till e-postkontot
define('MAIL_FROM',     'din-epost@dindoman.se');   // avsändaradress (samma som SMTP_USER)
define('MAIL_FROM_NAME','Lindängen Bokningssystem');
define('ADMIN_TO',      'styrelsen@dindoman.se');   // mottagare — styrelsens adress
// ────────────────────────────────────────────────────────────

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['name']) || empty($data['checkin']) || empty($data['checkout'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Saknade fält']);
    exit;
}

$name     = htmlspecialchars(trim($data['name']));
$checkin  = htmlspecialchars(trim($data['checkin']));
$checkout = htmlspecialchars(trim($data['checkout']));
$nights   = intval($data['nights'] ?? 1);

// ── Bygg e-postmeddelandet ───────────────────────────────────
$subject = "Ny bokning: Gästlägenhet $checkin – $checkout";

$body = "Hej,\n\n"
      . "En ny bokning av gästlägenheten har registrerats:\n\n"
      . "  Bokad av:          $name\n"
      . "  Incheckning:       $checkin\n"
      . "  Utcheckning:       $checkout\n"
      . "  Antal nätter:      $nights\n\n"
      . "Utcheckning sker senast kl. 11:00 på utcheckningsdagen.\n\n"
      . "---\n"
      . "Detta meddelande skickades automatiskt av bokningssystemet på lindangen.se.\n";

// ── Skicka via SMTP med socket (ingen extern lib krävs) ──────
$result = sendSmtp(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS,
                   MAIL_FROM, MAIL_FROM_NAME, ADMIN_TO, $subject, $body);

if ($result === true) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $result]);
}

// ── SMTP-funktion (SSL, port 465) ────────────────────────────
function sendSmtp($host, $port, $user, $pass, $from, $fromName, $to, $subject, $body) {
    $errno = 0; $errstr = '';
    $sock = @fsockopen("ssl://$host", $port, $errno, $errstr, 10);
    if (!$sock) return "Kunde inte ansluta till SMTP: $errstr ($errno)";

    $read = function() use ($sock) {
        $r = '';
        while ($line = fgets($sock, 512)) {
            $r .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $r;
    };

    $cmd = function($c) use ($sock, $read) {
        fputs($sock, $c . "\r\n");
        return $read();
    };

    $read(); // banner
    $cmd("EHLO " . gethostname());
    $resp = $cmd("AUTH LOGIN");
    if (strpos($resp, '334') === false) { fclose($sock); return "AUTH misslyckades: $resp"; }
    $cmd(base64_encode($user));
    $resp = $cmd(base64_encode($pass));
    if (strpos($resp, '235') === false) { fclose($sock); return "Fel lösenord/användarnamn: $resp"; }

    $cmd("MAIL FROM:<$from>");
    $cmd("RCPT TO:<$to>");
    $cmd("DATA");

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = "From: $encodedFrom <$from>\r\n"
             . "To: $to\r\n"
             . "Subject: $encodedSubject\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body));

    $resp = $cmd($headers . "\r\n.");
    $cmd("QUIT");
    fclose($sock);

    if (strpos($resp, '250') !== false) return true;
    return "SMTP svarade oväntat: $resp";
}
