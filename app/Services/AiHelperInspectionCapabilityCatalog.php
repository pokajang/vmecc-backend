<?php

namespace App\Services;

final class AiHelperInspectionCapabilityCatalog
{
    /**
     * This server-side catalogue mirrors the implemented inspection registry.
     * Keep the visible titles exact so deterministic answers cannot drift from
     * the type cards users see in VMECC.
     *
     * @return array<int, array{title: string, purpose: string, purpose_ms: string}>
     */
    public function all(): array
    {
        return [
            [
                'title' => 'Emergency Response Auxiliary Equipment',
                'purpose' => 'Emergency response auxiliary equipment inventory and condition checks.',
                'purpose_ms' => 'Pemeriksaan inventori dan keadaan peralatan bantuan tindak balas kecemasan.',
            ],
            [
                'title' => 'Fire Extinguisher',
                'purpose' => 'Extinguisher location, certification, physical, signage, box, and operational checks.',
                'purpose_ms' => 'Pemeriksaan lokasi, pensijilan, fizikal, papan tanda, kotak dan operasi alat pemadam api.',
            ],
            [
                'title' => 'Fire Truck Daily Readiness',
                'purpose' => 'Truck-first daily readiness roster and one-off checks with seeded rows and required readings.',
                'purpose_ms' => 'Pemeriksaan kesiapsiagaan harian lori, bacaan wajib dan ruang peralatan.',
            ],
            [
                'title' => 'High Angle Rescue Equipment',
                'purpose' => 'Workbook-backed rescue kit checks with fixed quantity, condition, and remarks.',
                'purpose_ms' => 'Pemeriksaan kuantiti, keadaan dan catatan kit penyelamatan tempat tinggi.',
            ],
            [
                'title' => 'Hydraulic Rescue Tools',
                'purpose' => 'Hydraulic tool physical, mechanical, leakage, function, and defect checks.',
                'purpose_ms' => 'Pemeriksaan fizikal, mekanikal, kebocoran, fungsi dan kecacatan alat hidraulik.',
            ],
            [
                'title' => 'SCBA',
                'purpose' => 'SCBA back plate, cylinder, and face mask condition checks.',
                'purpose_ms' => 'Pemeriksaan keadaan plat belakang, silinder dan topeng muka SCBA.',
            ],
            [
                'title' => 'Health Safety Environment',
                'purpose' => 'Record an unsafe act or unsafe condition with a description and photo.',
                'purpose_ms' => 'Merekod perbuatan atau keadaan tidak selamat berserta penerangan dan foto.',
            ],
            [
                'title' => 'General Inspection',
                'purpose' => 'General condition, access, housekeeping, hazard, and compliance notes.',
                'purpose_ms' => 'Catatan keadaan umum, akses, kekemasan, bahaya dan pematuhan.',
            ],
        ];
    }
}
