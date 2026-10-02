import type { Messages } from "./en";

/**
 * Bahasa Indonesia (ADR 0060).
 *
 * Typed as `Messages`: a key missing here, or one English does not have,
 * fails the build. Product terms the team already says in English — workflow,
 * role, template, webhook, token — stay as they are, the way people using the
 * product say them.
 */
export const id: Messages = {
  // ── Shared ────────────────────────────────────────────────────────────────
  "common.unreachable": "Server tidak dapat dihubungi. Silakan coba lagi.",
  "toast.done": "Selesai",
  "toast.removed": "Dihapus",
  "toast.dismiss": "Tutup",

  // ── Shell: sidebar, bottom bar, header ───────────────────────────────────
  "nav.home": "Beranda",
  "nav.myWork": "Pekerjaan Saya",
  "nav.inbox": "Kotak Masuk",
  "nav.work": "Pekerjaan",
  "nav.projects": "Proyek",
  "nav.calendar": "Kalender",
  "nav.timesheet": "Lembar Waktu",
  "nav.recurring": "Berulang",
  "nav.flow": "Alur",
  "nav.people": "Orang",
  "nav.teams": "Tim",
  "nav.departments": "Departemen",
  "nav.settings": "Pengaturan",
  "nav.browseAll": "Lihat semua…",
  "nav.main": "Utama",
  "nav.primary": "Navigasi utama",
  "nav.search": "Cari",
  "nav.more": "Lainnya",
  "nav.moreNavigation": "Navigasi lainnya",
  "nav.notifications": "Notifikasi",
  "nav.dueOrOverdue": "jatuh tempo atau terlambat",
  "nav.unread": "belum dibaca",
  "nav.countLabel": "{label}: {count} {unit}",

  // ── Account menu ─────────────────────────────────────────────────────────
  "account.menu": "Akun",
  "account.profile": "Profil Anda",
  "account.switchTo": "Beralih ke",
  "account.signOut": "Keluar",
  "account.oneMoment": "Sebentar…",

  // ── Command palette ──────────────────────────────────────────────────────
  "palette.search": "Cari",
  "palette.placeholder": "Cari pekerjaan, proyek, orang…",
  "palette.searching": "mencari…",
  "palette.nothing": "Tidak ada yang cocok dengan “{terms}”.",
  "palette.hint": "Cari berdasarkan judul, referensi, atau isi komentar.",
  "palette.group.work_item": "Pekerjaan",
  "palette.group.project": "Proyek",
  "palette.group.person": "Orang",
  "palette.match.comment": "di komentar",
  "palette.match.description": "di deskripsi",
  "palette.keys.move": "↑↓ untuk berpindah",
  "palette.keys.open": "↵ untuk membuka",
  "palette.keys.close": "esc untuk menutup",
  "palette.tooMany": "Terlalu banyak pencarian. Coba lagi sebentar lagi.",

  // ── Settings index ───────────────────────────────────────────────────────
  "settings.title": "Pengaturan",
  "settings.areas.one": "{count} area",
  "settings.areas.other": "{count} area",
  "settings.panel.title": "Area",
  "settings.panel.description": "Hanya yang boleh Anda buka yang ditampilkan.",
  "settings.organization.label": "Organisasi",
  "settings.organization.description": "Tempat Anda bekerja, dan berapa lama Anda tetap masuk.",
  "settings.notifications.label": "Notifikasi",
  "settings.notifications.description": "Pemberitahuan mana yang sampai ke Anda, dan lewat mana.",
  "settings.language.label": "Bahasa",
  "settings.language.description": "Bahasa yang dipakai antarmuka ini untuk Anda.",
  "settings.twoFactor.label": "Autentikasi dua faktor",
  "settings.twoFactor.description": "Kode dari ponsel Anda, sebagai tambahan kata sandi.",
  "settings.sessions.label": "Perangkat yang masuk",
  "settings.sessions.description":
    "Setiap perangkat yang dapat bertindak atas nama Anda, dan cara mengakhirinya.",
  "settings.apiTokens.label": "Token API",
  "settings.apiTokens.description":
    "Izinkan skrip atau integrasi bertindak atas nama Anda, tanpa kata sandi Anda.",
  "settings.serviceAccounts.label": "Service account",
  "settings.serviceAccounts.description":
    "Integrasi yang bertindak atas namanya sendiri, dengan sebuah role — dan tetap ada meski pembuatnya sudah pergi.",
  "settings.sso.label": "Single sign-on",
  "settings.sso.description":
    "Penyedia identitas yang dipakai orang-orang Anda untuk masuk, dan apakah kata sandi masih berlaku.",
  "settings.roles.label": "Role",
  "settings.roles.description": "Apa yang boleh dilakukan setiap role, termasuk role yang Anda buat sendiri.",
  "settings.fields.label": "Field kustom",
  "settings.fields.description":
    "Apa yang ditanyakan organisasi ini tentang item kerja, di luar field bawaan.",
  "settings.templates.label": "Template",
  "settings.templates.description":
    "Titik awal untuk pekerjaan baru — tipe, prioritas, checklist, tenggat.",
  "settings.webhooks.label": "Webhook",
  "settings.webhooks.description":
    "Ke mana aturan otomatisasi boleh mengirim event organisasi ini, dan apakah event itu sampai.",
  "settings.audit.label": "Log audit",
  "settings.audit.description": "Siapa melakukan apa, dan kapan — proses masuk, undangan, perubahan role.",
  "settings.workflows.label": "Workflow",
  "settings.workflows.description": "Status yang dilalui pekerjaan, dan perpindahan mana yang sah.",
  "settings.rules.label": "Aturan otomatisasi",
  "settings.rules.description":
    "Apa yang dilakukan sistem dengan sendirinya — dan apa yang benar-benar sudah dilakukannya.",

  // ── Settings → Language ──────────────────────────────────────────────────
  "language.title": "Bahasa",
  "language.description":
    "Bahasa antarmuka ini untuk Anda, di setiap perangkat tempat Anda masuk.",
  "language.panel.title": "Bahasa antarmuka",
  "language.panel.description":
    "Layar yang belum diterjemahkan tetap berbahasa Inggris, begitu pula pesan dari server untuk sementara.",
  "language.current": "Saat ini",
  "language.saved": "Tersimpan. Antarmuka sekarang memakai {language}.",

  // ── Sign in ──────────────────────────────────────────────────────────────
  "login.metaTitle": "Masuk",
  "login.title": "Masuk",
  "login.subtitle": "Gunakan akun organisasi Anda.",
  "login.email": "Email",
  "login.password": "Kata sandi",
  "login.forgot": "Lupa kata sandi?",
  "login.sso": "Masuk dengan single sign-on",
  "login.submit": "Masuk",
  "login.submitting": "Sedang masuk…",
  "login.language": "Bahasa",
  "login.code.title": "Masukkan kode Anda",
  "login.code.body":
    "Enam digit dari aplikasi autentikator Anda. Jika perangkatnya hilang, gunakan salah satu kode pemulihan yang sudah Anda simpan.",
  "login.code.label": "Kode",
  "login.code.submit": "Verifikasi",
  "login.code.submitting": "Memeriksa…",
  "login.code.restart": "Mulai lagi",
  "login.expired": "Proses masuk ini sudah kedaluwarsa. Silakan mulai lagi.",
};
