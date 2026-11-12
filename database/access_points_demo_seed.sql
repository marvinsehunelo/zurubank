-- database/access_points_demo_seed.sql
--
-- MADE-UP DEMO DATA for the ZuruBank sandbox. None of these ATMs or branches exists.
-- Every name says "Demo". The towns are real, so the locator has towns to show; the addresses
-- are invented landmarks, there are no coordinates, and the cash figures are made up.
--
-- OPTIONAL, and applied BY HAND. Nothing in the application runs this file and nothing adds
-- access points on its own: without it, GET /Backend/api/v1/access_points.php answers with an
-- empty list.
--
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/access_points_demo_seed.sql
--
-- Apply it after the endpoint has answered once: its first request creates the access_points
-- table (atm_terminals is already in the live database). Safe to re-run: rows that are already
-- there are left as they are.
--
-- What the endpoint then lists (the rules are in Backend/lib/access_points.php):
--     Zurubank Demo ATM - Main Mall        cash_available true   terminal ACTIVE, cash left
--     Zurubank Demo ATM - Bus Rank         cash_available false  terminal ACTIVE, empty; takes deposits
--     Zurubank Demo ATM - Airport          cash_available null   no terminal record
--     Zurubank Demo Branch - Gaborone      also pays out cash-outs
--     Zurubank Demo Branch - Francistown
-- and does not list:
--     Zurubank Demo ATM - Old Depot        its terminal is OUT_OF_SERVICE
--
-- To remove the demo data again:
--     DELETE FROM access_points WHERE name LIKE 'Zurubank Demo %';
--     DELETE FROM atm_terminals WHERE atm_id LIKE 'DEMO-ATM-%';

BEGIN;

INSERT INTO atm_terminals (atm_id, location, status, total_cash, reserved_cash, last_sync)
VALUES
    ('DEMO-ATM-01', 'Demo - Main Mall', 'ACTIVE',         50000.0000, 2000.0000, NOW()),
    ('DEMO-ATM-02', 'Demo - Bus Rank',  'ACTIVE',             0.0000,    0.0000, NOW()),
    ('DEMO-ATM-03', 'Demo - Old Depot', 'OUT_OF_SERVICE', 10000.0000,    0.0000, NOW())
ON CONFLICT (atm_id) DO NOTHING;

INSERT INTO access_points (kind, name, town, address, hours, atm_id, deposit, cash_out)
SELECT v.kind, v.name, v.town, v.address, v.hours, v.atm_id, v.deposit, v.cash_out
FROM (VALUES
    (1, 'ATM',    'Zurubank Demo ATM - Main Mall',      'Gaborone',    'Main Mall, north entrance (demo)',   '24 hours',                             'DEMO-ATM-01', FALSE, FALSE),
    (2, 'ATM',    'Zurubank Demo ATM - Bus Rank',       'Francistown', 'Bus rank, beside the kiosks (demo)', '24 hours',                             'DEMO-ATM-02', TRUE,  FALSE),
    (3, 'ATM',    'Zurubank Demo ATM - Old Depot',      'Gaborone',    '',                                   '24 hours',                             'DEMO-ATM-03', FALSE, FALSE),
    (4, 'ATM',    'Zurubank Demo ATM - Airport',        'Maun',        'Airport arrivals hall (demo)',       'Mon-Sun 06:00-22:00',                  NULL,          FALSE, FALSE),
    (5, 'BRANCH', 'Zurubank Demo Branch - Gaborone',    'Gaborone',    'Demo House, ground floor',           'Mon-Fri 08:30-16:30, Sat 08:30-12:00', NULL,          FALSE, TRUE),
    (6, 'BRANCH', 'Zurubank Demo Branch - Francistown', 'Francistown', '',                                   'Mon-Fri 08:30-16:30',                  NULL,          FALSE, FALSE)
) AS v (n, kind, name, town, address, hours, atm_id, deposit, cash_out)
WHERE NOT EXISTS (
    SELECT 1 FROM access_points a WHERE a.kind = v.kind AND a.name = v.name
)
ORDER BY v.n;  -- so the ids follow the order above

COMMIT;
