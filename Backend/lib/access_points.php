<?php
/**
 * Backend/lib/access_points.php — ZURUBANK
 *
 * The rules behind GET /Backend/api/v1/access_points.php: the public list of this bank's ATMs
 * and branches that VouchMorph fetches for its access point locator.
 *
 * Including this file opens no database connection and prints nothing, so
 * tests/access_points_test.php checks the rules without a database.
 *
 * WHERE THE DATA COMES FROM
 *   access_points  The bank's public directory: one row per ATM or branch it publishes, with
 *                  the name, town, address, hours and coordinates. Created here on first use;
 *                  the bank had no table holding any of these.
 *   atm_terminals  Already in the live database: what the bank records about a terminal
 *                  (status, total_cash, reserved_cash, last_sync). An ATM's directory row
 *                  points at its terminal with atm_id. Only read here, never written.
 *
 * THE RULES
 *   Listed          in_service is true and, for an ATM that has a terminal record, that
 *                   record's status is ACTIVE. Rows that cannot be described the way the
 *                   contract requires (unknown kind, no name, no town) are left out.
 *   cash_available  ATM with a terminal record whose cash figures have been synced: true when
 *                   total_cash is more than reserved_cash, otherwise false. Anything else
 *                   (a branch, an ATM without a terminal record, figures never synced) is
 *                   null, "not reported". The figures themselves never leave this file.
 *   services        ATM: cash_out and atm_voucher, plus deposit when its row says so.
 *                   Branch: deposit and account_opening, plus cash_out when its row says so.
 *
 * Nothing here invents data: every value sent comes from a row.
 */

declare(strict_types=1);

const ACCESS_POINTS_INSTITUTION = 'ZURUBANK';

/**
 * Creates the two tables when they are missing. Changes nothing when they exist.
 *
 * A table that cannot be created is logged and not fatal here: two first requests can race to
 * create it, and this database user may not be allowed to. If the table is there, the read
 * works; if it is not, the read fails and the caller answers for that.
 */
function access_points_ensure_tables(PDO $pdo): void
{
    $tables = [
        // In the live database already (see backup.sql). Created here, with the same columns,
        // only where it is missing: a database built from database/zurubank.sql has none.
        'atm_terminals' => "
            CREATE TABLE IF NOT EXISTS atm_terminals (
                atm_id        VARCHAR(50) PRIMARY KEY,
                location      TEXT,
                status        VARCHAR(20) DEFAULT 'ACTIVE',
                total_cash    NUMERIC(20,4) DEFAULT 0,
                reserved_cash NUMERIC(20,4) DEFAULT 0,
                last_sync     TIMESTAMP
            )
        ",
        // atm_id   ATM only: the terminal in atm_terminals, when the bank has a record of it.
        // deposit  an ATM that also takes deposits (a branch always does).
        // cash_out a branch that also pays out cash-outs (an ATM always does).
        'access_points' => "
            CREATE TABLE IF NOT EXISTS access_points (
                id         SERIAL PRIMARY KEY,
                kind       VARCHAR(10)  NOT NULL CHECK (kind IN ('ATM', 'BRANCH')),
                name       VARCHAR(120) NOT NULL CHECK (name <> ''),
                town       VARCHAR(80)  NOT NULL CHECK (town <> ''),
                address    VARCHAR(255) NOT NULL DEFAULT '',
                hours      VARCHAR(120) NOT NULL DEFAULT '',
                atm_id     VARCHAR(50)  UNIQUE,
                deposit    BOOLEAN      NOT NULL DEFAULT FALSE,
                cash_out   BOOLEAN      NOT NULL DEFAULT FALSE,
                latitude   NUMERIC(9,6),
                longitude  NUMERIC(9,6),
                in_service BOOLEAN      NOT NULL DEFAULT TRUE
            )
        ",
    ];

    foreach ($tables as $table => $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log("[access_points] could not create {$table}: " . $e->getMessage());
        }
    }
}

/**
 * Every directory row, each ATM with its terminal record when there is one. Takes no input
 * from the request. Which rows are listed is decided by access_points_payload().
 */
function access_points_fetch_rows(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT a.id, a.kind, a.name, a.town, a.address, a.hours,
               a.deposit, a.cash_out, a.latitude, a.longitude, a.in_service,
               t.atm_id        AS terminal_id,
               t.status        AS terminal_status,
               t.total_cash    AS terminal_total_cash,
               t.reserved_cash AS terminal_reserved_cash,
               t.last_sync     AS terminal_last_sync
        FROM access_points a
        LEFT JOIN atm_terminals t ON a.kind = 'ATM' AND t.atm_id = a.atm_id
        ORDER BY a.kind, a.town, a.name, a.id
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** A boolean column as PDO may hand it over: true/false, 1/0, 't'/'f'. */
function access_point_flag(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 't', 'true'], true);
}

function access_point_kind(array $row): string
{
    return strtoupper(trim((string)($row['kind'] ?? '')));
}

/** Does this bank have a terminal record for the row (ATM rows only)? */
function access_point_has_terminal(array $row): bool
{
    return access_point_kind($row) === 'ATM' && ($row['terminal_id'] ?? null) !== null;
}

function access_point_is_in_service(array $row): bool
{
    if (!access_point_flag($row['in_service'] ?? false)) {
        return false;
    }
    if (access_point_has_terminal($row)) {
        return strtoupper(trim((string)($row['terminal_status'] ?? ''))) === 'ACTIVE';
    }

    return true;
}

/** true, false, or null when the bank has not reported it. Never an amount. */
function access_point_cash_available(array $row): ?bool
{
    if (!access_point_has_terminal($row)) {
        return null;
    }

    $total = $row['terminal_total_cash'] ?? null;
    if (($row['terminal_last_sync'] ?? null) === null || !is_numeric($total)) {
        return null;
    }

    $reserved = $row['terminal_reserved_cash'] ?? null;
    $reserved = is_numeric($reserved) ? (float)$reserved : 0.0;

    return ((float)$total - $reserved) > 0;
}

/** In the order the contract lists them. */
function access_point_services(array $row): array
{
    $kind = access_point_kind($row);

    if ($kind === 'ATM') {
        $services = ['cash_out', 'atm_voucher'];
        if (access_point_flag($row['deposit'] ?? false)) {
            $services[] = 'deposit';
        }

        return $services;
    }

    if ($kind === 'BRANCH') {
        $services = access_point_flag($row['cash_out'] ?? false) ? ['cash_out'] : [];
        $services[] = 'deposit';
        $services[] = 'account_opening';

        return $services;
    }

    return [];
}

/** [latitude, longitude] as numbers, or [null, null] unless both are present and possible. */
function access_point_coordinates(array $row): array
{
    $latitude  = $row['latitude'] ?? null;
    $longitude = $row['longitude'] ?? null;

    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return [null, null];
    }

    $latitude  = (float)$latitude;
    $longitude = (float)$longitude;

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        return [null, null];
    }

    return [$latitude, $longitude];
}

/**
 * One row as the contract's access point, or null when the row cannot be described the way
 * the contract requires. Only the keys below are ever sent, whatever else the row carries.
 */
function access_point_to_json(array $row): ?array
{
    $kind = access_point_kind($row);
    $id   = trim((string)($row['id'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $town = trim((string)($row['town'] ?? ''));

    if (!in_array($kind, ['ATM', 'BRANCH'], true) || $id === '' || $name === '' || $town === '') {
        return null;
    }

    [$latitude, $longitude] = access_point_coordinates($row);

    return [
        'id'             => $kind . '-' . $id,
        'kind'           => $kind,
        'name'           => $name,
        'town'           => $town,
        'address'        => trim((string)($row['address'] ?? '')),
        'hours'          => trim((string)($row['hours'] ?? '')),
        'services'       => access_point_services($row),
        'cash_available' => access_point_cash_available($row),
        'latitude'       => $latitude,
        'longitude'      => $longitude,
    ];
}

/** The whole response body. $readAt is the Unix time the rows were read. */
function access_points_payload(array $rows, int $readAt): array
{
    $accessPoints = [];
    foreach ($rows as $row) {
        if (!access_point_is_in_service($row)) {
            continue;
        }
        $accessPoint = access_point_to_json($row);
        if ($accessPoint !== null) {
            $accessPoints[] = $accessPoint;
        }
    }

    return [
        'institution'   => ACCESS_POINTS_INSTITUTION,
        'updated_at'    => gmdate('Y-m-d\TH:i:s\Z', $readAt),
        'access_points' => $accessPoints,
    ];
}
