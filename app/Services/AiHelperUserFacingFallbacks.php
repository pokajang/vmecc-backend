<?php

namespace App\Services;

final class AiHelperUserFacingFallbacks
{
    public function missingKnowledge(string $language, string $sourceMode = 'any'): string
    {
        if ($sourceMode === 'system') {
            return $language === 'bm'
                ? 'Maaf, saya belum menemui panduan yang sesuai untuk tindakan itu. Cuba nyatakan nama menu, halaman atau tindakan yang ingin dilakukan.'
                : 'Sorry, I have not found suitable guidance for that action. Try naming the menu, page, or action you want to use.';
        }

        return $language === 'bm'
            ? 'Maaf, saya belum mempunyai rujukan yang mencukupi untuk mengesahkan perkara itu. Cuba nyatakan prosedur atau urusan yang dimaksudkan. Jika ia melibatkan polisi organisasi, sila semak dengan penyelia atau pihak yang bertanggungjawab.'
            : 'Sorry, I do not yet have enough reference information to verify that. Try naming the procedure or matter involved. If it concerns organisational policy, please check with your supervisor or the responsible team.';
    }

    public function lowConfidence(string $language): string
    {
        return $language === 'bm'
            ? 'Maaf, saya belum dapat memastikan jawapan yang tepat. Boleh nyatakan perkara khusus yang anda ingin lakukan?'
            : 'Sorry, I cannot yet confirm an accurate answer. What specific task or detail do you need?';
    }

    public function providerUnavailable(string $language): string
    {
        return $language === 'bm'
            ? 'Maaf, saya tidak dapat memberikan jawapan sekarang. Sila cuba lagi sebentar.'
            : 'Sorry, I cannot provide an answer right now. Please try again shortly.';
    }

    public function knowledgeTemporarilyUnavailable(string $language): string
    {
        return $language === 'bm'
            ? 'Maaf, maklumat rujukan untuk menjawab soalan ini belum tersedia buat sementara waktu. Sila cuba lagi kemudian atau hubungi pihak yang bertanggungjawab jika perkara ini berterusan.'
            : 'Sorry, the reference information needed to answer this question is temporarily unavailable. Please try again later or contact the responsible team if this continues.';
    }
}
