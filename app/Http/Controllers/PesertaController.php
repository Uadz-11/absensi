<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Absensi;
use App\Models\Pengaturan;
use App\Models\Pengajuan;
use Illuminate\Support\Facades\Log;

class PesertaController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $absensiHariIni = Absensi::where('user_id', $user->id)
            ->whereDate('tanggal', now()->format('Y-m-d'))
            ->first();

        $pengaturan = Pengaturan::first();

        // Statistik kehadiran bulan ini
        $bulanIni = now()->format('Y-m');
        $totalHadir = Absensi::where('user_id', $user->id)
            ->where('status', 'hadir')
            ->whereRaw("DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulanIni])
            ->count();
        $totalIzin = Absensi::where('user_id', $user->id)
            ->whereIn('status', ['izin', 'sakit'])
            ->whereRaw("DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulanIni])
            ->count();

        return view('peserta.dashboard', compact('user', 'absensiHariIni', 'pengaturan', 'totalHadir', 'totalIzin'));
    }

    public function absenForm(Request $request)
    {
        $user = Auth::user();
        $type = $request->query('type', 'masuk');
        $pengaturan = Pengaturan::first();

        $absensiHariIni = Absensi::where('user_id', $user->id)
            ->whereDate('tanggal', now()->format('Y-m-d'))
            ->first();

        return view('peserta.absen', compact('type', 'pengaturan', 'user', 'absensiHariIni'));
    }

    /**
     * Endpoint untuk mengambil face descriptor milik user yang sedang login.
     * Backend menentukan user dari Auth::user(), BUKAN dari input frontend.
     * Descriptor dikirim ke frontend hanya untuk proses perbandingan wajah,
     * dan hanya pada saat sesi presensi aktif.
     */
    public function getDescriptor(Request $request)
    {
        $user = Auth::user();

        if (!$user->face_descriptor) {
            return response()->json([
                'success' => false,
                'message' => 'Wajah Anda belum terdaftar. Silakan hubungi Admin untuk melakukan registrasi wajah.',
                'not_registered' => true,
            ], 200);
        }

        // Kembalikan descriptor milik user yang login (bukan user lain)
        return response()->json([
            'success' => true,
            'descriptor' => json_decode($user->face_descriptor),
        ]);
    }

    public function absenMasuk(Request $request)
    {
        $user = Auth::user();

        // Validasi wajah sudah dilakukan di frontend (face_verified=true dari JS)
        // Backend memvalidasi ulang: user harus punya face descriptor
        if (!$user->face_descriptor) {
            return back()->with('error', 'Wajah Anda belum terdaftar. Silakan hubungi Admin.');
        }

        $request->validate([
            'foto' => 'required',
        ]);

        // Cek sudah absen masuk hari ini
        $absensi = Absensi::where('user_id', $user->id)
            ->whereDate('tanggal', now()->format('Y-m-d'))
            ->first();

        if ($absensi && $absensi->jam_masuk) {
            return back()->with('error', 'Anda sudah melakukan absen masuk hari ini.');
        }

        // Validasi foto base64 basic
        if (!str_contains($request->foto, 'base64,')) {
            return back()->with('error', 'Data foto tidak valid.');
        }

        // Simpan foto
        try {
            $imageParts = explode(";base64,", $request->foto);
            $imageTypeAux = explode("image/", $imageParts[0]);
            $imageType = $imageTypeAux[1] ?? 'jpeg';
            $imageBase64 = base64_decode($imageParts[1]);
            $fileName = 'absen/' . $user->id . '_masuk_' . time() . '.' . $imageType;
            \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $imageBase64);
        } catch (\Exception $e) {
            Log::error('Gagal simpan foto absen masuk: ' . $e->getMessage());
            return back()->with('error', 'Gagal menyimpan foto. Silakan coba lagi.');
        }

        if (!$absensi) {
            $absensi = new Absensi();
            $absensi->user_id = $user->id;
            $absensi->tanggal = now()->format('Y-m-d');
        }

        $pengaturan = Pengaturan::first();
        $jamMasuk = now()->format('H:i:s');
        $jamBatas = $pengaturan->jam_masuk_batas ?? '08:00:00';

        $absensi->jam_masuk = $jamMasuk;
        $absensi->status = 'hadir';
        $absensi->foto_masuk = $fileName;
        // Kosongkan field GPS (tidak digunakan)
        $absensi->lat_masuk = null;
        $absensi->long_masuk = null;
        $absensi->jarak_masuk = null;
        $absensi->save();

        Log::info("Absen masuk berhasil: User {$user->id} ({$user->name}) jam {$jamMasuk}");

        return back()->with('success', 'Absen masuk berhasil dicatat pada pukul ' . date('H:i', strtotime($jamMasuk)) . ' WIB.');
    }

    public function absenPulang(Request $request)
    {
        $user = Auth::user();

        if (!$user->face_descriptor) {
            return back()->with('error', 'Wajah Anda belum terdaftar. Silakan hubungi Admin.');
        }

        $request->validate([
            'foto' => 'required',
        ]);

        $absensi = Absensi::where('user_id', $user->id)
            ->whereDate('tanggal', now()->format('Y-m-d'))
            ->first();

        if (!$absensi || !$absensi->jam_masuk) {
            return back()->with('error', 'Anda belum melakukan absen masuk hari ini.');
        }

        if ($absensi->jam_pulang) {
            return back()->with('error', 'Anda sudah melakukan absen pulang hari ini.');
        }

        // Simpan foto
        try {
            $imageParts = explode(";base64,", $request->foto);
            $imageTypeAux = explode("image/", $imageParts[0]);
            $imageType = $imageTypeAux[1] ?? 'jpeg';
            $imageBase64 = base64_decode($imageParts[1]);
            $fileName = 'absen/' . $user->id . '_pulang_' . time() . '.' . $imageType;
            \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $imageBase64);
        } catch (\Exception $e) {
            Log::error('Gagal simpan foto absen pulang: ' . $e->getMessage());
            return back()->with('error', 'Gagal menyimpan foto. Silakan coba lagi.');
        }

        $jamPulang = now()->format('H:i:s');
        $absensi->jam_pulang = $jamPulang;
        $absensi->lat_pulang = null;
        $absensi->long_pulang = null;
        $absensi->jarak_pulang = null;
        $absensi->foto_pulang = $fileName;
        $absensi->save();

        Log::info("Absen pulang berhasil: User {$user->id} ({$user->name}) jam {$jamPulang}");

        return back()->with('success', 'Absen pulang berhasil dicatat pada pukul ' . date('H:i', strtotime($jamPulang)) . ' WIB.');
    }

    public function izinSakitForm()
    {
        return view('peserta.izin_sakit');
    }

    public function submitIzinSakit(Request $request)
    {
        $request->validate([
            'jenis' => 'required|in:izin,sakit',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'alasan' => 'required|string|max:255',
            'bukti_dokumen' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        $fileName = null;
        if ($request->hasFile('bukti_dokumen')) {
            $file = $request->file('bukti_dokumen');
            $fileName = 'pengajuan/' . Auth::id() . '_' . time() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('public', $fileName);
        }

        Pengajuan::create([
            'user_id' => Auth::id(),
            'jenis' => $request->jenis,
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'alasan' => $request->alasan,
            'keterangan' => $request->keterangan,
            'bukti_dokumen' => $fileName,
            'status' => 'menunggu',
        ]);

        return redirect()->route('peserta.dashboard')->with('success', 'Pengajuan berhasil dikirim dan menunggu persetujuan Admin.');
    }

    public function riwayat(Request $request)
    {
        $riwayat = Absensi::where('user_id', Auth::id())
            ->orderBy('tanggal', 'desc')
            ->paginate(10);

        return view('peserta.riwayat', compact('riwayat'));
    }
}
