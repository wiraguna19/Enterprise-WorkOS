<?php

declare(strict_types=1);

/*
 * Validation messages in Indonesian (ADR 0060).
 *
 * The rules this codebase actually uses, not Laravel's whole catalogue: a rule
 * missing here falls back to the English line by itself, so nothing breaks
 * when a new one is added — it reads in English until someone writes its line.
 */
return [
    'accepted' => ':attribute harus disetujui.',
    'after' => ':attribute harus tanggal setelah :date.',
    'after_or_equal' => ':attribute harus tanggal yang sama dengan atau setelah :date.',
    'array' => ':attribute harus berupa daftar.',
    'before' => ':attribute harus tanggal sebelum :date.',
    'before_or_equal' => ':attribute harus tanggal yang sama dengan atau sebelum :date.',
    'boolean' => ':attribute harus benar atau salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'date_format' => ':attribute tidak sesuai format :format.',
    'digits' => ':attribute harus :digits digit.',
    'distinct' => ':attribute berisi nilai yang berulang.',
    'email' => ':attribute harus alamat email yang valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'file' => ':attribute harus berupa berkas.',
    'gt' => [
        'numeric' => ':attribute harus lebih dari :value.',
        'string' => ':attribute harus lebih dari :value karakter.',
        'array' => ':attribute harus berisi lebih dari :value item.',
    ],
    'gte' => [
        'numeric' => ':attribute harus lebih dari atau sama dengan :value.',
        'string' => ':attribute harus minimal :value karakter.',
        'array' => ':attribute harus berisi minimal :value item.',
    ],
    'in' => ':attribute yang dipilih tidak valid.',
    'integer' => ':attribute harus bilangan bulat.',
    'lt' => [
        'numeric' => ':attribute harus kurang dari :value.',
        'string' => ':attribute harus kurang dari :value karakter.',
        'array' => ':attribute harus berisi kurang dari :value item.',
    ],
    'lte' => [
        'numeric' => ':attribute harus kurang dari atau sama dengan :value.',
        'string' => ':attribute harus maksimal :value karakter.',
        'array' => ':attribute harus berisi maksimal :value item.',
    ],
    'max' => [
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'file' => ':attribute tidak boleh lebih dari :max kilobyte.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
        'array' => ':attribute tidak boleh berisi lebih dari :max item.',
    ],
    'min' => [
        'numeric' => ':attribute minimal :min.',
        'file' => ':attribute minimal :min kilobyte.',
        'string' => ':attribute minimal :min karakter.',
        'array' => ':attribute harus berisi minimal :min item.',
    ],
    'not_in' => ':attribute yang dipilih tidak valid.',
    'numeric' => ':attribute harus berupa angka.',
    'password' => [
        'letters' => ':attribute harus berisi setidaknya satu huruf.',
        'mixed' => ':attribute harus berisi setidaknya satu huruf besar dan satu huruf kecil.',
        'numbers' => ':attribute harus berisi setidaknya satu angka.',
        'symbols' => ':attribute harus berisi setidaknya satu simbol.',
        'uncompromised' => ':attribute ini pernah muncul dalam kebocoran data. Pilih :attribute lain.',
    ],
    'present' => ':attribute harus ada.',
    'prohibited' => ':attribute tidak boleh diisi.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi bila :other adalah :value.',
    'required_unless' => ':attribute wajib diisi kecuali :other ada di :values.',
    'required_with' => ':attribute wajib diisi bila :values ada.',
    'required_without' => ':attribute wajib diisi bila :values tidak ada.',
    'starts_with' => ':attribute harus diawali salah satu dari: :values.',
    'string' => ':attribute harus berupa teks.',
    'timezone' => ':attribute harus zona waktu yang valid.',
    'unique' => ':attribute sudah dipakai.',
    'url' => ':attribute harus URL yang valid.',
    'uuid' => ':attribute harus UUID yang valid.',

    /*
     * Field names as a person reads them. The web shows these messages next to
     * the form, so "nama wajib diisi" rather than "name wajib diisi". A field
     * not listed is shown by its key with underscores as spaces, which is
     * what Laravel does in every language.
     */
    'attributes' => [
        'access' => 'akses',
        'assignee_id' => 'penanggung jawab',
        'audience_id' => 'kelompok sasaran',
        'audience_type' => 'jenis sasaran',
        'body' => 'isi',
        'code' => 'kode',
        'comment' => 'komentar',
        'custom_fields' => 'field kustom',
        'department_id' => 'departemen',
        'description' => 'deskripsi',
        'due_at' => 'tenggat',
        'due_date' => 'tenggat',
        'email' => 'email',
        'end_date' => 'tanggal selesai',
        'estimate_hours' => 'estimasi jam',
        'events' => 'peristiwa',
        'expires_at' => 'tanggal kedaluwarsa',
        'expires_in_days' => 'masa berlaku',
        'file' => 'berkas',
        'key' => 'key',
        'label' => 'label',
        'locale' => 'bahasa',
        'membership_id' => 'orang',
        'milestone_id' => 'milestone',
        'name' => 'nama',
        'parent_id' => 'induk',
        'password' => 'kata sandi',
        'priority' => 'prioritas',
        'reason' => 'alasan',
        'role' => 'role',
        'start_date' => 'tanggal mulai',
        'starts_at' => 'waktu mulai',
        'status' => 'status',
        'team_id' => 'tim',
        'text_size' => 'ukuran teks',
        'title' => 'judul',
        'to_state_id' => 'status tujuan',
        'trigger' => 'pemicu',
        'type' => 'tipe',
        'url' => 'alamat',
        'visibility' => 'visibilitas',
    ],
];
