<?php

namespace App\Services;

final class AiHelperInputResponseService
{
    public function message(AiHelperInputAssessment $assessment, bool $malay): string
    {
        return match ($assessment->decision) {
            AiHelperInputAssessment::REFUSE_SENSITIVE => $malay
                ? 'Maaf, saya tidak dapat membantu dengan permintaan itu kerana ia mungkin melibatkan maklumat sensitif atau terhad. Sila buang sebarang kata laluan, token akses, nombor pengenalan, maklumat perbankan, atau maklumat peribadi sebelum mencuba semula. Saya masih boleh membantu berkaitan fungsi VMECC, aliran kerja, laporan, pemeriksaan, dan perkara kerja yang lain.'
                : 'I’m sorry, but I can’t help with that request because it may involve sensitive or restricted information. Please remove any passwords, access tokens, identity numbers, banking details, or private personal information before trying again. I can still help with VMECC features, workflows, reports, inspections, and other work-related topics.',
            AiHelperInputAssessment::REFUSE_EXFILTRATION => $malay
                ? 'Maaf, saya tidak dapat membantu mendapatkan arahan tersembunyi, dokumen peribadi, atau data yang tidak dibenarkan. Saya masih boleh membantu dengan fungsi VMECC dan perkara kerja yang tersedia melalui akses anda.'
                : 'I’m sorry, but I can’t help retrieve hidden instructions, private documents, or unauthorized data. I can still help with VMECC features and work-related topics available through your access.',
            AiHelperInputAssessment::REPHRASE => $malay
                ? 'Saya belum dapat mengenal pasti soalan atau tugas tersebut. Sila nyatakan halaman, jenis rekod, atau tindakan VMECC yang anda perlukan.'
                : 'I could not identify the question or task yet. Please name the VMECC page, record type, or action you need.',
            default => $malay
                ? 'Saya mengenali topik tersebut tetapi memerlukan sedikit lagi maklumat. Apakah tindakan atau hasil yang anda perlukan?'
                : 'I recognize the topic but need a little more detail. What action or outcome do you need?',
        };
    }
}
