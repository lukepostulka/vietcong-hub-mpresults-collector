<?php

/* @version 1.0.0 */

/**
 * collector.php
 * 
 * This script:
 * 1. Finds files in a specific directory (e.g. `mpresults/`).
 * 2. For each file, verifies validity (e.g. "Map end rule: 00:00").
 * 3. Extracts map, date, time, team tags, players, and stats.
 * 4. Identifies consistent clan tags for US and VC teams.
 * 5. Builds a JSON payload.
 * 6. Sends the JSON to a public API endpoint.
 * 7. Keeps track of processed files (optional: store processed hashes in a DB or a local file).
 */

// CONFIGURATION

/**
 * Server name to match results collected by this script.
 * Can be any string but recommended to use a kebab-case ID of your server (e.g., "RC_WAR_1" or "*RC* Warserver 1").
 * @var string
 */
$server_name = '';

/**
 * Tag to identify the results of matches collected by this script.
 * Can be any string but recommended to use kebab-case (e.g. "LIGA-Q1-2025")
 * @var string
 */
$tag = '';

/**
 * Allowed modes to process (e.g., "CTF", "ATG").
 * All other modes and maps will be skipped.
 * @var array
 */
$allowed_modes = ["CTF", "ATG"];

/**
 * Directory where result files are stored. By default, this PHP file should be placed in your Vietcong server root directory.
 * @var string
 */
$directory = __DIR__ . '/mpresults';

/**
 * Date filter: If you want the script to parse only today's files to reduce load on the API (optional).
 * @var bool
 */
$onlyToday = false;

/**
 * Today's date in "Y-m-d" format.
 * @var string
 */
$today = date("Y-m-d");

/**
 * Minimum number of players sharing a clan tag for tag identification.
 * @var int
 */
$minTagMatches = 3;

/**
 * API endpoint to send parsed results.
 * @var string
 */
$apiEndpoint = "https://api.vietcong-hub.cz/v1/rounds";

/**
 * File name pattern (e.g., endresults-YYYY-MM-DD_HH-mm-ss.txt).
 * @var string
 */
$filePattern = '/endresults-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.txt$/';

/**
 * Identify a clan tag from a list of player names.
 *
 * New approach:
 * - Generate all substrings (≥3 chars) for each player's name.
 * - Track in how many distinct player names each substring appears.
 * - Choose the substring that appears in the most players (≥ minTagMatches).
 * - In a tie, choose the longest substring.
 *
 * @param array $playerNames
 * @param int   $minTagMatches
 * @return string
 */
function identifyClanTag(array $playerNames, int $minTagMatches)
{
  if (empty($playerNames)) {
    return "";
  }

  // A map of substring => set of player indices that contain this substring
  $substringPlayersMap = [];

  foreach ($playerNames as $playerIndex => $name) {
    $nameLength = mb_strlen($name);
    // To avoid counting duplicates multiple times for the same player,
    // use a set (array flipped) to store unique substrings per player.
    $playerSubstrings = [];

    for ($start = 0; $start < $nameLength; $start++) {
      for ($length = 3; $length <= $nameLength - $start; $length++) {
        $substr = mb_substr($name, $start, $length);
        // Store this substring for the player
        $playerSubstrings[$substr] = true;
      }
    }

    // Now add these substrings to the global map with the player's index
    foreach ($playerSubstrings as $substr => $_) {
      if (!isset($substringPlayersMap[$substr])) {
        $substringPlayersMap[$substr] = [];
      }
      $substringPlayersMap[$substr][$playerIndex] = true;
    }
  }

  // Evaluate substrings: We need the one that appears in the most players,
  // but at least $minTagMatches players.
  $bestTag = "";
  $bestCount = 0;
  $bestLength = 0;

  foreach ($substringPlayersMap as $substr => $playersSet) {
    $count = count($playersSet);
    if ($count >= $minTagMatches) {
      // Check if this is better than our current best:
      // Priority 1: highest count
      // Priority 2: if tie, longest substring
      if ($count > $bestCount || ($count == $bestCount && mb_strlen($substr) > $bestLength)) {
        $bestTag = $substr;
        $bestCount = $count;
        $bestLength = mb_strlen($substr);
      }
    }
  }

  return $bestTag;
}


/**
 * Parse player lines for a given team block.
 *
 * Expected format per player line:
 * "<player_name> pnts: X     kills: Y    dths: Z"
 *
 * We'll extract these stats using a regex. Player name may contain spaces and special chars.
 *
 * @param array $lines Lines corresponding to a team's players
 * @return array parsed players data
 */
function parsePlayers(array $lines)
{
  $players = [];
  $pattern = '/^(?<name>.*?)pnts:\s+(?<points>\d+)\s+kills:\s+(?<kills>\d+)\s+dths:\s+(?<deaths>\d+)/i';

  foreach ($lines as $line) {
    $line = trim($line);
    if (preg_match($pattern, $line, $matches)) {
      $name   = trim($matches['name']);

      // in case name starts with "Spectator(", don't include the player
      if (stripos($name, "Spectator(") === 0) {
        continue;
      }

      $points = (int)$matches['points'];
      $kills  = (int)$matches['kills'];
      $deaths = (int)$matches['deaths'];
      $k_d = ($deaths > 0) ? round($kills / $deaths, 4) : $kills; // handle division by zero

      $players[] = [
        'name'   => $name,
        'points' => $points,
        'kills'  => $kills,
        'deaths' => $deaths,
        'k_d'    => $k_d
      ];
    }
  }
  return $players;
}

/**
 * Send JSON to the API endpoint via POST.
 *
 * @param string $url
 * @param array  $payload
 * @return array [success => bool, response => string]
 */
function sendToApi($url, array $payload)
{
  $jsonData = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  $options = [
    'http' => [
      'header'  => "Content-Type: application/json\r\n",
      'method'  => 'POST',
      'content' => $jsonData,
      'timeout' => 30
    ]
  ];
  $context  = stream_context_create($options);
  $result   = @file_get_contents($url, false, $context);

  if ($result === FALSE) {
    return ['success' => false, 'response' => 'Error contacting API'];
  }

  return ['success' => true, 'response' => $result];
}


// MAIN LOGIC

// Get list of files
$files = scandir($directory);
if (!$files) {
  die("Could not read directory: $directory");
}

$payloads = [];

foreach ($files as $file) {
  if ($file === '.' || $file === '..') continue;

  $filePath = $directory . '/' . $file;

  if ($file != 'endresults-2022-04-01_20-32-34.txt') {
    // continue;
  }

  // Check if file matches the pattern
  if (!preg_match($filePattern, $file)) {
    continue; // skip files that don’t match naming convention
  }

  // If you only want today's files, you can do:
  if ($onlyToday && !str_contains($file, $today)) {
    continue; // skip files not from today
  }

  // Read file content
  $content = file_get_contents($filePath);
  if (!$content) {
    // Could not read file
    continue;
  }

  // Basic validation: check for "Map end rule: 00:00"
  if (!str_contains($content, "Map end rule: 00:00")) {
    // Not a fully ended match
    continue;
  }

  // Parse important lines:
  // Expected format:
  // "Map: SomeMapName"
  // "Date: YYYY-MM-DD"
  // "Time: HH:MM:SS"
  // "US Army points: X"
  // "Vietcong points: Y"

  $lines = explode("\n", $content);
  $lines = array_map('trim', $lines);
  $lines = array_filter($lines); // remove empty lines

  $map = "";
  $mode = "";
  $date = "";
  $time = "";
  $usPoints = null;
  $vcPoints = null;

  // We need to capture player lines for both teams
  $usPlayersLines = [];
  $vcPlayersLines = [];

  // Simple state machine: once we see "US Army points:" we read until we see "Vietcong points:"
  $readingUSPlayers = false;
  $readingVCPlayers = false;

  foreach ($lines as $line) {
    if (stripos($line, "Map:") === 0) {
      $map = trim(substr($line, 4));
    } elseif (stripos($line, "Date:") === 0) {
      $date = trim(substr($line, 5));
    } elseif (stripos($line, "Time:") === 0) {
      $time = trim(substr($line, 5));
    } elseif (stripos($line, "US Army points:") === 0) {
      $usPoints = (int)trim(substr($line, 15));
      $readingUSPlayers = true;
      $readingVCPlayers = false;
    } elseif (stripos($line, "Vietcong points:") === 0) {
      $vcPoints = (int)trim(substr($line, 16));
      $readingUSPlayers = false;
      $readingVCPlayers = true;
    } else {
      // Player lines
      if ($readingUSPlayers && !empty($line)) {
        // Until we hit "Vietcong points", these lines belong to US players
        if (stripos($line, "Vietcong points:") === 0) {
          // Just a safety check, but we already caught this above
          $readingUSPlayers = false;
          $readingVCPlayers = true;
        } else {
          $usPlayersLines[] = $line;
        }
      } elseif ($readingVCPlayers && !empty($line)) {
        $vcPlayersLines[] = $line;
      }
    }
  }

  // Check if map contains any of the allowed modes
  $mode_found = false;
  foreach ($allowed_modes as $allowed_mode) {
    if (str_contains($map, $allowed_mode)) {
      $mode = $allowed_mode;
      $mode_found = true;
      break;
    }
  }

  // Skip if no allowed mode is found
  if (!$mode_found) {
    continue;
  }

  // Remove the mode from the map name
  $map = str_replace($mode, "", $map);

  // If any of the required fields is missing, skip this file
  if (empty($map) || empty($date) || empty($time) || is_null($usPoints) || is_null($vcPoints)) {
    echo "Invalid match: missing data in $file<br>";
    continue;
  }

  // Parse players
  $usPlayers = parsePlayers($usPlayersLines);
  $vcPlayers = parsePlayers($vcPlayersLines);

  // Identify clan tags
  $usNames = array_column($usPlayers, 'name');
  $vcNames = array_column($vcPlayers, 'name');

  $usTag = identifyClanTag($usNames, $minTagMatches);
  $vcTag = identifyClanTag($vcNames, $minTagMatches);

  // Check validity: Are the tags found and is match fully ended?
  // If tags are mandatory and must have at least $minTagMatches players:
  if (empty($usTag) || empty($vcTag)) {
    // Not valid match, skip safely
    // echo "Invalid match: missing tags in $file<br>";
    continue;
  }

  // Build player data keyed by original name
  $players_us_data = [];
  foreach ($usPlayers as $p) {
    $players_us_data[$p['name']] = [
      'points' => $p['points'],
      'kills'  => $p['kills'],
      'deaths' => $p['deaths'],
      'k_d'    => $p['k_d']
    ];
  }

  $players_vc_data = [];
  foreach ($vcPlayers as $p) {
    $players_vc_data[$p['name']] = [
      'points' => $p['points'],
      'kills'  => $p['kills'],
      'deaths' => $p['deaths'],
      'k_d'    => $p['k_d']
    ];
  }

  // Compute MD5 hash of file content for uniqueness
  $hash = md5($content);

  // Construct the payload
  $payload = [
    'hash' => $hash,
    'file' => $file,
    'tag' => $tag,
    'server_name' => $server_name,
    'match' => [
      'map'         => $map,
      'mode'        => $mode,
      'date'        => $date,
      'time'        => $time,
      'points_us'   => $usPoints,
      'points_vc'   => $vcPoints,
      'team_us'     => $usTag,
      'team_vc'     => $vcTag
    ],
    'players_us' => $players_us_data,
    'players_vc' => $players_vc_data
  ];

  $payloads[] = $payload;
}


// Send to API
$response = sendToApi($apiEndpoint, $payloads);

if ($response['success']) {
  echo "Successfully sent " . count($payloads) . " matches to the API<br>";
} else {
  echo "Error sending to API: " . $response['response'] . "<br>";
}

die();

// Debug output

echo "<pre>";

// print_r($payloads);
// die();

// echo json_encode($payloads);
// die();

$i = 1;
foreach ($payloads as $payload) {
  // print_r($payload);
  echo $i++ . ". ";
  echo $payload['match']['team_us'] . " vs " . $payload['match']['team_vc'] . " " . $payload['match']['points_us'] . ":" . $payload['match']['points_vc'] . " (" . $payload['match']['map'] . ") – " . $payload['file'] . "<br>";
}

echo "Total files processed: " . count($files) . "<br>";
echo "Done.<br>";

echo "</pre>";
