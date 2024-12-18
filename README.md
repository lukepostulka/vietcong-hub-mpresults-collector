# Vietcong Match Parser

This PHP script processes match result files generated by the Vietcong game server, extracts relevant match and player data, and sends it to a public API for further processing and storage.

## Features

1. **File Parsing:** Scans a directory (e.g., `mpresults/`) for result files matching a specific pattern (e.g., `endresults-YYYY-MM-DD_HH-mm-ss.txt`).
2. **Validation:** Ensures each file contains valid match results (e.g., "Map end rule: 00:00", at least 3 players from same clan in a team and so on).
3. **Data Extraction:**
   - Match details: map name, date, time, mode, and team points.
   - Player stats: kills, deaths, points, and kill/death ratio.
4. **Team Identification:** Identifies consistent clan tags for US and VC teams based on player names.
5. **JSON Payload:** Builds a JSON payload with the parsed data.
6. **API Integration:** (Optional) Sends the payload to a public API endpoint.
7. **Tracking:** Allows tracking processed files to avoid reprocessing.

## Configuration

All configurations can be modified within the script:

- `server_name`: Name of the server collecting the match results (e.g., `'*RC* Warserver 1'`).
- `tag`: A tag to identify the matches collected (e.g., `'LIGA-Q1-2025'`).
- `allowed_modes`: Allowed game modes (e.g., `["CTF", "ATG"]`).
- `directory`: Directory containing the match result files (default: `__DIR__ . '/mpresults'`).
- `onlyToday`: Set to `true` to process only today's files. Recomemnded for lowering API load if the script is called by CRON once every X minutes/hours in a day.
- `apiEndpoint`: URL of the API endpoint to send the processed JSON payload.
- `minTagMatches`: Minimum number of players sharing a clan tag for identification (default: `3`).

## How It Works

1. **Locate Files:**
   - Scans the specified directory for files matching the naming pattern.

2. **Validate Files:**
   - Ensures files contain valid match results with the "Map end rule: 00:00" marker.

3. **Parse Data:**
   - Extracts match details, including map, date, time, and team points.
   - Extracts player statistics: points, kills, deaths, and kill/death ratio.

4. **Identify Clan Tags:**
   - Analyzes player names to determine consistent clan tags for US and VC teams.

5. **Generate Payload:**
   - Creates a JSON payload containing match and player data.

6. **Send Data:**
   - (Optional) Sends the JSON payload to the specified API endpoint.

## Example JSON Payload

```json
{
  "hash": "abc123",
  "file": "endresults-2024-12-18_20-30-00.txt",
  "tag": "LIGA-Q1-2025",
  "server_name": "*RC* Warserver 1",
  "match": {
    "map": "NVA Base",
    "mode": "CTF",
    "date": "2024-12-18",
    "time": "20:30:00",
    "points_us": 10,
    "points_vc": 8,
    "team_us": "USClan",
    "team_vc": "VCClan"
  },
  "players_us": {
    "Player1": {
      "points": 15,
      "kills": 10,
      "deaths": 5,
      "k_d": 2.0
    },
    "Player2": {
      "points": 8,
      "kills": 5,
      "deaths": 3,
      "k_d": 1.6667
    }
  },
  "players_vc": {
    "PlayerA": {
      "points": 12,
      "kills": 8,
      "deaths": 6,
      "k_d": 1.3333
    },
    "PlayerB": {
      "points": 6,
      "kills": 3,
      "deaths": 4,
      "k_d": 0.75
    }
  }
}
