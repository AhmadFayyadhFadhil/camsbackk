<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflow Verifikasi Checklist Harian Cleaning Service
    |--------------------------------------------------------------------------
    | Alur: CS submit -> Supervisor Verifikator (Pak Kusnan) -> Supervisor
    | Tertinggi (Pak Winaryo) approval akhir -> Selesai.
    |
    | SLA bersifat sementara 24 jam kalender (nonstop) sampai ada kebijakan
    | operasional real. Nilai aktif dibaca dari system_settings
    | (key: verification_sla_hours); angka di bawah hanya default/fallback.
    */
    'verification' => [
        'sla_hours' => (int) env('CAMS_VERIFICATION_SLA_HOURS', 24),

        /*
        | Identitas akun hierarki Supervisor. TIDAK ada nilai bawaan untuk
        | username / email / password (data real perusahaan). Isi lewat .env.
        |
        | - Jika akun sudah ada (dicocokkan lewat username, email, atau
        |   nama lengkap yang unik), seeder hanya memasang level hierarkinya.
        | - Jika akun belum ada, seeder membuat akun HANYA bila username dan
        |   email diisi. Password diambil dari env; bila kosong, seeder membuat
        |   password acak dan menampilkannya sekali di konsol.
        */
        'supervisors' => [
            'head' => [
                'full_name' => env('CAMS_HEAD_SUPERVISOR_NAME', 'Winaryo'),
                'username'  => env('CAMS_HEAD_SUPERVISOR_USERNAME'),
                'email'     => env('CAMS_HEAD_SUPERVISOR_EMAIL'),
                'password'  => env('CAMS_HEAD_SUPERVISOR_PASSWORD'),
            ],
            'verifier' => [
                'full_name' => env('CAMS_VERIFIER_SUPERVISOR_NAME', 'Kusnan'),
                'username'  => env('CAMS_VERIFIER_SUPERVISOR_USERNAME'),
                'email'     => env('CAMS_VERIFIER_SUPERVISOR_EMAIL'),
                'password'  => env('CAMS_VERIFIER_SUPERVISOR_PASSWORD'),
            ],
            'operational' => [
                'full_name' => env('CAMS_OPERATIONAL_SUPERVISOR_NAME', 'Denny'),
                'username'  => env('CAMS_OPERATIONAL_SUPERVISOR_USERNAME'),
                'email'     => env('CAMS_OPERATIONAL_SUPERVISOR_EMAIL'),
                'password'  => env('CAMS_OPERATIONAL_SUPERVISOR_PASSWORD'),
            ],
        ],
    ],

];
