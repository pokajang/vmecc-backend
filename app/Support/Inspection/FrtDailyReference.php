<?php

namespace App\Support\Inspection;

final class FrtDailyReference
{
    public const MAIN_LOCATION = 'FIRE TRUCK';

    public const TRUCK_REFERENCE = [
        'plateNo' => 'AJG9555',
        'roadTaxExpiry' => '13/02/2026',
        'insuranceExpiry' => '13/02/2026',
        'puspakomExpiry' => '19/02/2026',
    ];

    private const DAILY_SECTION_ROWS = [
        'LOCKER 01' => [
            ['1', 'FIRE HOSE 2.5"', '6', 'status'],
            ['2', 'FIRE HOSE 1.5"', '4', 'status'],
            ['3', 'LIFEBUOY', '1', 'status'],
            ['4', 'SPINAL BOARD', '1', 'status'],
            ['5', 'FOLDABLE STRETCHER', '1', 'status'],
            ['6', 'STOKES STRETCHER', '1', 'status'],
        ],
        'LOCKER 02' => [
            ['7', 'HYDRAULIC MOTOR PUMP', '1', 'status'],
            ['8', 'HYDRAULIC HOSE', '1', 'status'],
            ['9', 'HYDRAULIC CUTTER', '1', 'status'],
            ['10', 'HYDRAULIC SPREADER', '1', 'status'],
            ['11', 'HYDRAULIC COMBI TOOLS', '1', 'status'],
            ['12', 'RAM', '1', 'status'],
        ],
        'LOCKER 03' => [
            ['13', 'HOSE REEL WITH NOZZLE', '1', 'status'],
            ['14', 'HOSE REEL LUG WRENCH', '1', 'status'],
            ['15', 'SPARE NOZZLE 1"', '1', 'status'],
            ['16', 'REFILLING HOSE 2.5"', '1', 'status'],
            ['17', 'HOSE RAMP', '1', 'status'],
            ['18', 'WEBBING', '1', 'status'],
            ['19', 'CONE', '2', 'status'],
        ],
        'LOCKER 04' => [
            ['20', 'GODIVA FIRE PUMP', '1', 'status'],
            ['21', 'WATER TANK LEVEL', '4', 'status'],
            ['22', 'FOAM LEVEL (A)', '2', 'status'],
            ['23', 'FOAM LEVEL (B)', '1', 'status'],
            ['24', 'WATER OUTLET 2.5"', '3', 'status'],
            ['25', 'WATER INLET 2.5"', '1', 'status'],
            ['26', 'FOAM OUTLET CAFS 2.5"', '1', 'status'],
            ['27', 'WATER SUCTION INLET 4"', '1', 'status'],
            ['28', 'ROOF MONITOR', '1', 'status'],
            ['29', 'ROOF MONITOR R/CONTROLLER', '1', 'status'],
            ['30', 'WATER TANK MANHOLE LID', '1', 'status'],
            ['31', 'FOAM TANK MANHOLE LID', '2', 'status'],
            ['32', 'ROOF FIX TOOLBOX', '1', 'status'],
        ],
        'LOCKER 05' => [
            ['33', 'DIVIDING BREECHING (CONTROLLER)', '2', 'status'],
            ['34', 'DIVIDING BREECHING', '1', 'status'],
            ['35', 'COLLECTING BREECHING', '2', 'status'],
            ['36', 'FIRE EXTINGUISHER ABC 6KG', '1', 'status'],
            ['37', 'HANDLINE NOZZLE', '2', 'status'],
            ['38', 'PLAIN NOZZLE', '2', 'status'],
            ['39', '4" INLET COLLECTING CAP', '1', 'status'],
            ['40', 'HYDRANT ADAPTOR', '1', 'status'],
            ['41', 'HARD SUCTION WRENCH SPANNER', '1', 'status'],
            ['42', 'HYDRANT KEY', '1', 'status'],
            ['43', 'AFFF (25 L)', '1', 'status'],
            ['44', 'FOAM INDUCTOR WITH TUBE', '1', 'status'],
            ['45', 'FOAM MAKING BRANCH', '1', 'status'],
        ],
        'LOCKER 06' => [
            ['46', 'SHACKLE WITH CHAIN', '1', 'status'],
            ['47', 'SHACKLE WITH HOOK', '1', 'status'],
            ['48', 'SHOVEL', '2', 'status'],
            ['49', 'AXE', '1', 'status'],
            ['50', 'AFFF FOAM (SPARE)', '1', 'status'],
        ],
        'LOCKER 07' => [
            ['51', 'SCBA SET (SPARE)', '4', 'status'],
        ],
        'LOCKER 08' => [
            ['52', 'CHOKE', '2', 'status'],
            ['53', 'WORKING HARNESS', '1', 'status'],
            ['54', 'YELLOW ROPE', '1', 'status'],
            ['55', 'LIFE JACKET', '2', 'status'],
        ],
        self::MAIN_LOCATION => [
            ['56', 'RADIATOR LEVEL GAUGE', 'N/A', 'status'],
            ['57', 'BATTERY WATER LEVEL GAUGE', 'N/A', 'status'],
            ['58', 'ENGINE OIL LEVEL GAUGE', 'N/A', 'status'],
            ['59', 'RADIO COMMUNICATION', 'N/A', 'status'],
            ['60', 'AIR CONDITIONER', 'N/A', 'status'],
            ['61', 'HORN', 'N/A', 'status'],
            ['62', 'SIDE MIRROR', 'N/A', 'status'],
            ['63', 'BACK MIRROR', 'N/A', 'status'],
            ['64', 'REVERSE CAMERA', 'N/A', 'status'],
            ['65', 'PANEL CONDITION', 'N/A', 'status'],
            ['66', 'FIXED PA SYSTEM (SIREN)', 'N/A', 'status'],
            ['67', 'AIR HORN', 'N/A', 'status'],
            ['68', 'BRAKE SYSTEM', 'N/A', 'status'],
            ['69', 'CABIN LIGHT', 'N/A', 'status'],
            ['70', 'HEAD LAMP', 'N/A', 'status'],
            ['71', 'BUMPER FOG LIGHT', 'N/A', 'status'],
            ['72', 'BACK LAMP', 'N/A', 'status'],
            ['73', 'BREAK LAMP', 'N/A', 'status'],
            ['74', 'SIGNAL LAMP', 'N/A', 'status'],
            ['75', 'HAZARD LAMP', 'N/A', 'status'],
            ['76', 'REAR FLASHLIGHT', 'N/A', 'status'],
            ['77', 'LOCKER LAMP', 'N/A', 'status'],
            ['78', 'EMERGENCY LIGHT BAR', 'N/A', 'status'],
            ['79', 'DASH MOUNTED SEARCH LIGHT', 'N/A', 'status'],
            ['80', 'WINDSHIELD (HEAD TOP)', 'N/A', 'status'],
            ['81', 'FIRST AID BOX', '1', 'status'],
            ['82', 'RENAULT MANUAL BOOK', '1', 'status'],
            ['83', 'EMERGENCY TRIANGLE', '1', 'status'],
            ['84', 'MANUAL HAND JACK (10T)', '1', 'status'],
            ['85', 'HAND PUMP GREASER', '1', 'status'],
            ['86', 'TYRE PRESSURE HOSE', '1', 'status'],
            ['87', 'PNEUMATIC HOSES', '1', 'status'],
            ['88', 'WINCH ADAPTOR ACCESSORIES', '1', 'status'],
            ['89', 'TRUCK HEAD LIFT SPANNER', '1', 'status'],
            ['90', 'OVERALL BODY', 'N/A', 'status'],
            ['91', 'MILEAGE (ODOMETER)', '', 'reading'],
            ['92', 'FUEL LEVEL (%)', '', 'reading'],
        ],
    ];

    private const ONE_OFF_SECTION_ROWS = [
        'TRUCK CHECKLIST' => [
            ['1', 'POWER WINDOW'],
            ['2', 'HEAD LAMP'],
            ['3', 'BACK LAMP'],
            ['4', 'BREAK LAMP'],
            ['5', 'LIGHT BAR'],
            ['6', 'HAZARD LAMP'],
            ['7', 'ACCESS LADDER'],
            ['8', 'TYRE'],
            ['9', 'SPARE TYRE'],
            ['10', 'FIRE MONITOR'],
            ['11', 'FIRE PUMP'],
            ['12', 'HYDRAULIC HOSE'],
            ['13', 'SIGNAL LAMP'],
            ['14', 'WIPER'],
            ['15', 'COMPARTMENT LIGHT'],
            ['16', 'ELECTRONIC SIREN'],
            ['17', 'AIR HORN'],
            ['18', 'HORN'],
            ['19', 'BATTERY'],
            ['20', 'SPOTLIGHT'],
            ['21', 'BODY AND CABIN'],
            ['22', 'SIDE MIRROR'],
            ['23', 'BREAK SYSTEM'],
        ],
        'LOCKER NO 01' => [
            ['24', '2.5 INCH FIRE HOSE : 1'],
            ['25', '1.5 INCH FIRE HOSE : 5'],
        ],
        'LOCKER NO 02' => [
            ['26', '2.5 INCH FIRE HOSE : 6'],
            ['27', '1.5 INCH FIRE HOSE'],
        ],
        'LOCKER NO 03' => [
            ['28', 'HOSE RAM : 2'],
            ['29', 'HOSE REEL : 1'],
            ['30', 'SUCTION ADAPTER : 1'],
        ],
        'LOCKER NO 04' => [
            ['31', 'GODIVA FIRE PUMP : ONE SET'],
            ['32', 'PRIMER : ONE SET'],
            ['33', 'PRESSURE GAUGE : 3'],
            ['34', 'OUTLET : 4'],
            ['35', 'INLET : 2'],
            ['36', 'ELECTRONIC PANEL : ONE SET'],
        ],
        'LOCKER NO 05' => [
            ['37', 'HYDRAULIC CUTTER : 1'],
            ['38', 'HYDRAULIC MOTOR PUMP : 1'],
            ['39', 'HYDRAULIC HOSE : 1'],
        ],
        'LOCKER NO 06' => [
            ['40', 'FIRE HELMET : 4'],
            ['41', 'FIRE SUIT : 4'],
        ],
        'LOCKER NO 07' => [
            ['42', 'HARD SUCTION HOSE : 4'],
            ['43', 'TOOLBOX : 1'],
            ['44', 'FIRE EXTINGUISHER : 2'],
        ],
        'CREW CABIN' => [
            ['45', 'BA SET : 4'],
            ['46', 'RADIO SET : 1'],
        ],
    ];

    public static function dailyRows(): array
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }

        $rows = [];
        foreach (self::DAILY_SECTION_ROWS as $location => $sectionRows) {
            foreach ($sectionRows as [$rowNumber, $equipment, $quantity, $rowKind]) {
                $rows[] = [
                    'id' => 'daily:fire-truck:'.$rowNumber,
                    'rowNumber' => $rowNumber,
                    'mainLocation' => self::MAIN_LOCATION,
                    'location' => $location,
                    'equipment' => $equipment,
                    'quantity' => $quantity,
                    'rowKind' => $rowKind,
                ];
            }
        }

        return $rows;
    }

    public static function oneOffRows(): array
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }

        $rows = [];
        foreach (self::ONE_OFF_SECTION_ROWS as $location => $sectionRows) {
            foreach ($sectionRows as [$rowNumber, $equipment]) {
                $rows[] = [
                    'id' => 'one-off:fire-truck:'.$rowNumber,
                    'rowNumber' => $rowNumber,
                    'mainLocation' => self::MAIN_LOCATION,
                    'location' => $location,
                    'equipment' => $equipment,
                ];
            }
        }

        return $rows;
    }

    public static function dailyRowMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::dailyRows() as $row) {
            $map[$row['id']] = $row;
        }

        return $map;
    }

    public static function oneOffRowMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::oneOffRows() as $row) {
            $map[$row['id']] = $row;
        }

        return $map;
    }

    public static function findDailyRow(string $id = '', string $rowNumber = ''): ?array
    {
        $normalizedId = trim($id);
        if ($normalizedId !== '' && array_key_exists($normalizedId, self::dailyRowMap())) {
            return self::dailyRowMap()[$normalizedId];
        }

        $normalizedRowNumber = trim($rowNumber);
        if ($normalizedRowNumber === '') {
            return null;
        }

        $lookupId = 'daily:fire-truck:'.$normalizedRowNumber;

        return self::dailyRowMap()[$lookupId] ?? null;
    }

    public static function findOneOffRow(string $id = '', string $rowNumber = ''): ?array
    {
        $normalizedId = trim($id);
        if ($normalizedId !== '' && array_key_exists($normalizedId, self::oneOffRowMap())) {
            return self::oneOffRowMap()[$normalizedId];
        }

        $normalizedRowNumber = trim($rowNumber);
        if ($normalizedRowNumber === '') {
            return null;
        }

        $lookupId = 'one-off:fire-truck:'.$normalizedRowNumber;

        return self::oneOffRowMap()[$lookupId] ?? null;
    }
}
