<?php

namespace App\Services\InspectionReports;

enum InspectionReportType: string
{
    case ErAux = 'er-aux';
    case FireExtinguisher = 'fire-extinguisher';
    case Frt = 'frt';
    case General = 'general';
    case HighAngle = 'high-angle';
    case Hse = 'hse';
    case Hydraulic = 'hydraulic';
    case Scba = 'scba';
    case Unknown = 'unknown';
}
